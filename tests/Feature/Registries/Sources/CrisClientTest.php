<?php

declare(strict_types=1);

namespace Tests\Feature\Registries\Sources;

use App\Enums\ImportRunStatus;
use App\Exceptions\RegistryRequestFailed;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\RegistryPage;
use App\Services\ClinicalTrials\Import\ClinicalTrialImporter;
use App\Services\ClinicalTrials\Import\ImportOptions;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\ClinicalTrialsGovSource;
use App\Services\Registries\SourceRegistry;
use App\Services\Registries\Sources\Cris\CrisSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class CrisClientTest extends TestCase
{
    use RefreshDatabase;

    private const string API = 'apis.data.go.kr/1352159/crisinfodataview/*';

    private const string KEY = 'test-secret-key';

    private const array JSON = ['Content-Type' => 'application/json;charset=UTF-8'];

    private const array DETAILS = [
        'KCT0002243' => 'synthetic_detail_KCT0002243.json',
        'KCT0009876' => 'synthetic_detail_KCT0009876.json',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 10, 'max_sleep_ms' => 100],
            'registries.options.cris.service_key' => self::KEY,
            'registries.sources' => [
                ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
                CrisSource::KEY => CrisSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/registries/cris/'.$name));
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(CrisSource::class)->client();
    }

    /**
     * @return array<string, string>
     */
    private static function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    private static function isDetail(Request $request, string $id): bool
    {
        return str_contains($request->url(), '/detail') && (self::queryOf($request)['crisNumber'] ?? null) === $id;
    }

    private function fakeApi(string $list = ''): void
    {
        Http::fake([self::API => function (Request $request) use ($list) {
            $query = self::queryOf($request);

            if (str_contains($request->url(), '/detail')) {
                return Http::response(self::fixture(self::DETAILS[$query['crisNumber'] ?? ''] ?? 'synthetic_detail_nodata.json'), 200, self::JSON);
            }

            $file = $list !== '' ? $list : (($query['pageNo'] ?? '1') === '2' ? 'synthetic_list_page2.json' : 'synthetic_list_page1.json');

            return Http::response(self::fixture($file), 200, self::JSON);
        }]);
    }

    public function test_the_list_is_paged_and_each_trial_is_read_in_detail(): void
    {
        $this->fakeApi();
        $client = $this->client();

        $first = $client->fetchPage(2);
        $second = $client->fetchPage(2, $first->nextPageToken);

        $this->assertCount(2, $first->studies);
        $this->assertSame('2', $first->nextPageToken);
        $this->assertSame('Recruiting', $first->studies[0]['records'][0]['recruitment_status_en']);
        $this->assertSame('https://apis.data.go.kr/1352159/crisinfodataview/detail?resultType=JSON&crisNumber=KCT0002243', $first->studies[0]['url']);
        $this->assertCount(2, $first->studies[1]['records'], 'Both rows of the trial are kept.');
        $this->assertCount(1, $second->studies);
        $this->assertSame('KCT0005555', $second->studies[0]['records'][0]['trial_id'], 'Without detail data, the list item is the record.');
        $this->assertNull($second->nextPageToken);

        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/list') && self::queryOf($request) === [
            'resultType' => 'JSON', 'pageNo' => '1', 'numOfRows' => '2', 'serviceKey' => self::KEY,
        ]);
        $this->assertFalse(RegistryPage::query()->where('url', 'like', '%'.self::KEY.'%')->exists(), 'The key is never stored.');
    }

    public function test_page_size_is_capped_at_fifty_rows(): void
    {
        $this->fakeApi('synthetic_list_empty.json');

        $this->client()->fetchPage(500);

        Http::assertSent(fn (Request $request): bool => (self::queryOf($request)['numOfRows'] ?? null) === '50');
    }

    public function test_an_incremental_run_reads_details_only_for_trials_updated_since(): void
    {
        $this->fakeApi();

        $page = $this->client()->fetchPage(2, null, CarbonImmutable::parse('2026-11-20', 'Asia/Seoul'));

        $this->assertCount(1, $page->studies);
        $this->assertSame('KCT0002243', $page->studies[0]['records'][0]['trial_id']);
        $this->assertSame('2', $page->nextPageToken, 'The whole list is still paged.');
        Http::assertSent(fn (Request $request): bool => self::isDetail($request, 'KCT0002243'));
        Http::assertNotSent(fn (Request $request): bool => self::isDetail($request, 'KCT0009876'));
    }

    public function test_an_empty_list_is_an_empty_last_page(): void
    {
        $this->fakeApi('synthetic_list_empty.json');

        $page = $this->client()->fetchPage(50);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
        Http::assertSentCount(1);
    }

    public function test_a_missing_key_fails_before_any_request(): void
    {
        config(['registries.options.cris.service_key' => '']);
        Http::fake();

        try {
            $this->client()->fetchPage(50);
            $this->fail('A missing key must stop the import.');
        } catch (RegistryRequestFailed $exception) {
            $this->assertStringContainsString('REGISTRY_CRIS_SERVICE_KEY', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_quota_error_with_http_200_fails(): void
    {
        $xml = '<OpenAPI_ServiceResponse><cmmMsgHeader><errMsg>SERVICE ERROR</errMsg>'
            .'<returnAuthMsg>LIMITED_NUMBER_OF_SERVICE_REQUESTS_EXCEEDS_ERROR</returnAuthMsg>'
            .'<returnReasonCode>22</returnReasonCode></cmmMsgHeader></OpenAPI_ServiceResponse>';
        Http::fake([self::API => Http::response($xml, 200, ['Content-Type' => 'text/xml;charset=UTF-8'])]);

        try {
            $this->client()->fetchPage(50);
            $this->fail('A quota error must not be read as an empty page.');
        } catch (RegistryRequestFailed $exception) {
            $this->assertStringContainsString('quota for CRIS exceeded', $exception->getMessage());
            $this->assertStringNotContainsString(self::KEY, $exception->getMessage());
        }
    }

    public function test_a_response_that_is_not_a_result_fails(): void
    {
        Http::fake([self::API => Http::response('<html><body>Maintenance</body></html>', 200, ['Content-Type' => 'text/html'])]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(50);
    }

    public function test_a_changed_json_shape_fails(): void
    {
        Http::fake([self::API => Http::response('{"trials": []}', 200, self::JSON)]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(50);
    }

    public function test_a_server_error_is_retried(): void
    {
        Http::fake([self::API => Http::sequence()->push('', 503)->push(self::fixture('synthetic_list_empty.json'), 200, self::JSON)]);

        $page = $this->client()->fetchPage(50);

        $this->assertSame([], $page->studies);
        Http::assertSentCount(2);
    }

    public function test_an_import_creates_the_studies_and_a_repeated_import_leaves_them_unchanged(): void
    {
        $this->fakeApi();
        $options = new ImportOptions(pageSize: 2, full: true, source: CrisSource::KEY);

        $first = $this->app->make(ClinicalTrialImporter::class)->import($options);

        $this->assertSame(ImportRunStatus::Succeeded, $first->status);
        $this->assertSame(3, $first->studies_created);
        $trial = ClinicalTrial::query()->where('source', CrisSource::KEY)->where('source_id', 'KCT0002243')->sole();
        $this->assertSame('RECRUITING', $trial->overall_status);
        $this->assertSame('Asan Medical Center', $trial->sponsor_name);
        $this->assertSame('json', $trial->raw_format);
        $this->assertSame(['NCT03012345'], $trial->secondaryIds()->pluck('secondary_id')->all());
        $this->assertSame(
            ['강남세브란스병원', '경희대학교병원'],
            ClinicalTrial::query()->where('source_id', 'KCT0009876')->sole()->locations()->orderBy('facility')->pluck('facility')->all(),
        );

        $second = $this->app->make(ClinicalTrialImporter::class)->import($options);

        $this->assertSame(ImportRunStatus::Succeeded, $second->status);
        $this->assertSame(0, $second->studies_created);
        $this->assertSame(3, $second->studies_unchanged);
        $this->assertSame(3, ClinicalTrial::query()->where('source', CrisSource::KEY)->count());
    }
}
