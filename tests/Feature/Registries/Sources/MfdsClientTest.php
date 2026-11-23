<?php

declare(strict_types=1);

namespace Tests\Feature\Registries\Sources;

use App\Enums\ImportRunStatus;
use App\Exceptions\RegistryAccessRefused;
use App\Exceptions\RegistryRequestFailed;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\RegistryPage;
use App\Services\ClinicalTrials\Import\ClinicalTrialImporter;
use App\Services\ClinicalTrials\Import\ImportOptions;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\ClinicalTrialsGovSource;
use App\Services\Registries\SourceRegistry;
use App\Services\Registries\Sources\Mfds\MfdsSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class MfdsClientTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = 'apis.data.go.kr/1471000/ClncExamPlanDtlService03/*';

    private const string KEY = 'test-secret-key';

    private const array JSON = ['Content-Type' => 'application/json;charset=UTF-8'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 10, 'max_sleep_ms' => 100],
            'registries.options.mfds.service_key' => self::KEY,
            'registries.sources' => [
                ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
                MfdsSource::KEY => MfdsSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/registries/mfds/'.$name));
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(MfdsSource::class)->client();
    }

    /**
     * @return array<string, string>
     */
    private static function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    private function fakePages(): void
    {
        Http::fake([self::ENDPOINT => fn (Request $request) => Http::response(
            self::fixture((self::queryOf($request)['pageNo'] ?? '1') === '2' ? 'synthetic_page2.json' : 'synthetic_page1.json'),
            200,
            self::JSON,
        )]);
    }

    public function test_pages_are_followed_and_the_key_is_sent_but_never_stored(): void
    {
        $this->fakePages();
        $client = $this->client();

        $first = $client->fetchPage(2);
        $second = $client->fetchPage(2, $first->nextPageToken);

        $this->assertCount(2, $first->studies);
        $this->assertSame('2', $first->nextPageToken);
        $this->assertSame('202400123', $first->studies[0]['item']['CLNC_TEST_SN']);
        $this->assertSame(
            'https://apis.data.go.kr/1471000/ClncExamPlanDtlService03/getClncExamPlanDtlInq03?type=json&clnc_test_sn=202400123',
            $first->studies[0]['url'],
        );
        $this->assertCount(1, $second->studies);
        $this->assertNull($second->nextPageToken);

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => self::queryOf($request) === [
            'type' => 'json', 'pageNo' => '1', 'numOfRows' => '2', 'serviceKey' => self::KEY,
        ]);
        $this->assertSame(2, RegistryPage::query()->where('source', MfdsSource::KEY)->count());
        $this->assertFalse(RegistryPage::query()->where('url', 'like', '%'.self::KEY.'%')->exists(), 'The key is never stored.');
    }

    public function test_an_incremental_run_filters_on_the_approval_date(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-23 10:00:00', 'Asia/Seoul'));
        Http::fake([self::ENDPOINT => Http::response(self::fixture('synthetic_page2.json'), 200, self::JSON)]);

        $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-11-20', 'Asia/Seoul'));

        Http::assertSent(fn (Request $request): bool => (self::queryOf($request)['clst_aprv_start_dt'] ?? null) === '20261120'
            && (self::queryOf($request)['clst_aprv_end_dt'] ?? null) === '20261124');
        CarbonImmutable::setTestNow();
    }

    public function test_no_data_is_an_empty_last_page(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::fixture('synthetic_nodata.json'), 200, self::JSON)]);

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_missing_key_fails_before_any_request(): void
    {
        config(['registries.options.mfds.service_key' => null]);
        Http::fake();

        try {
            $this->client()->fetchPage(100);
            $this->fail('A missing key must stop the import.');
        } catch (RegistryRequestFailed $exception) {
            $this->assertStringContainsString('REGISTRY_MFDS_SERVICE_KEY', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_quota_error_with_http_200_fails_without_the_key_in_the_message(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::fixture('synthetic_quota_exceeded.xml'), 200, ['Content-Type' => 'text/xml;charset=UTF-8'])]);

        try {
            $this->client()->fetchPage(100);
            $this->fail('A quota error must not be read as an empty page.');
        } catch (RegistryRequestFailed $exception) {
            $this->assertStringContainsString('quota', $exception->getMessage());
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        }
    }

    public function test_a_rejected_key_ends_the_run(): void
    {
        Http::fake([self::ENDPOINT => Http::response(self::fixture('nokey_response.json'), 401, self::JSON)]);

        $this->expectException(RegistryAccessRefused::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_response_that_is_not_a_result_fails(): void
    {
        Http::fake([self::ENDPOINT => Http::response('<html><body>Maintenance</body></html>', 200, ['Content-Type' => 'text/html'])]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_changed_json_shape_fails(): void
    {
        Http::fake([self::ENDPOINT => Http::response('{"data": {"rows": []}}', 200, self::JSON)]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_server_error_is_retried(): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()->push('', 500)->push(self::fixture('synthetic_page2.json'), 200, self::JSON)]);

        $page = $this->client()->fetchPage(2, '2');

        $this->assertCount(1, $page->studies);
        Http::assertSentCount(2);
    }

    public function test_an_import_creates_the_studies_and_a_repeated_import_leaves_them_unchanged(): void
    {
        $this->fakePages();
        $options = new ImportOptions(pageSize: 2, full: true, source: MfdsSource::KEY);

        $first = $this->app->make(ClinicalTrialImporter::class)->import($options);

        $this->assertSame(ImportRunStatus::Succeeded, $first->status);
        $this->assertSame(3, $first->studies_created);
        $trial = ClinicalTrial::query()->where('source', MfdsSource::KEY)->where('source_id', '202400123')->sole();
        $this->assertSame('RECRUITING', $trial->overall_status);
        $this->assertSame('json', $trial->raw_format);
        $this->assertSame(['ABC-101', 'Placebo'], $trial->interventions()->orderBy('name')->pluck('name')->all());

        $second = $this->app->make(ClinicalTrialImporter::class)->import($options);

        $this->assertSame(ImportRunStatus::Succeeded, $second->status);
        $this->assertSame(0, $second->studies_created);
        $this->assertSame(3, $second->studies_unchanged);
        $this->assertSame(3, ClinicalTrial::query()->where('source', MfdsSource::KEY)->count());
    }
}
