<?php

declare(strict_types=1);

namespace Tests\Feature\Registries\Sources;

use App\Enums\ImportRunStatus;
use App\Exceptions\RegistryRequestFailed;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Import\ClinicalTrialImporter;
use App\Services\ClinicalTrials\Import\ImportOptions;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\ClinicalTrialsGovSource;
use App\Services\Registries\SourceRegistry;
use App\Services\Registries\Sources\Isrctn\IsrctnSource;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class IsrctnClientTest extends TestCase
{
    use RefreshDatabase;

    private const string QUERY = 'www.isrctn.com/api/query/*';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'UTC'));
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 10, 'max_sleep_ms' => 100],
            'registries.sources' => [
                ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
                IsrctnSource::KEY => IsrctnSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/registries/isrctn/'.$name));
    }

    private static function xml(string $body): PromiseInterface
    {
        return Http::response($body, 200, ['Content-Type' => 'application/xml;charset=UTF-8']);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(IsrctnSource::class)->client();
    }

    private static function queryOf(Request $request): string
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return (string) ($query['q'] ?? '');
    }

    public function test_records_edited_since_a_date_are_read_in_one_window(): void
    {
        Http::fake([self::QUERY => self::xml(self::fixture('list.xml'))]);

        $page = $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-11-20'));

        $this->assertCount(2, $page->studies);
        $this->assertNull($page->nextPageToken, 'The window reaches tomorrow.');
        $this->assertStringContainsString('ISRCTN56688303', $page->studies[0]['xml']);
        $this->assertSame('https://www.isrctn.com/api/trial/ISRCTN56688303/format/default', $page->studies[0]['url']);
        Http::assertSent(fn (Request $request): bool => self::queryOf($request) === 'lastEdited GE 2026-11-20T00:00:00 AND lastEdited LT 2026-11-24T00:00:00'
            && str_contains($request->url(), 'limit=100'));
    }

    public function test_a_window_with_more_records_than_one_response_is_narrowed(): void
    {
        Http::fake([self::QUERY => Http::sequence()
            ->push(str_replace('totalCount="2"', 'totalCount="4"', self::fixture('list.xml')), 200, ['Content-Type' => 'application/xml'])
            ->push(self::fixture('list.xml'), 200, ['Content-Type' => 'application/xml'])]);

        $page = $this->client()->fetchPage(2, null, CarbonImmutable::parse('2026-11-20'));

        $this->assertCount(2, $page->studies);
        // 4 days held 4 records for 2 places: the window shrinks to 80% of half of it.
        $this->assertSame('2026-11-21T14:24:00|110592', $page->nextPageToken);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => self::queryOf($request) === 'lastEdited GE 2026-11-20T00:00:00 AND lastEdited LT 2026-11-21T14:24:00');
    }

    public function test_a_full_import_walks_windows_from_the_configured_start(): void
    {
        config(['registries.options.isrctn.full_import_from' => '2026-11-01', 'registries.options.isrctn.max_window_days' => 10]);
        Http::fake([self::QUERY => Http::sequence()
            ->push(self::fixture('empty.xml'), 200, ['Content-Type' => 'application/xml'])
            ->push(self::fixture('list.xml'), 200, ['Content-Type' => 'application/xml'])
            ->push(self::fixture('empty.xml'), 200, ['Content-Type' => 'application/xml'])]);
        $client = $this->client();

        $first = $client->fetchPage(100);
        $this->assertSame([], $first->studies);
        $this->assertSame('2026-11-11T00:00:00|864000', $first->nextPageToken);

        $second = $client->fetchPage(100, $first->nextPageToken);
        $this->assertCount(2, $second->studies);
        $this->assertSame('2026-11-21T00:00:00|864000', $second->nextPageToken);

        $third = $client->fetchPage(100, $second->nextPageToken);
        $this->assertSame([], $third->studies);
        $this->assertNull($third->nextPageToken, 'The last window ends at tomorrow.');

        Http::assertSent(fn (Request $request): bool => self::queryOf($request) === 'lastEdited GE 2026-11-01T00:00:00 AND lastEdited LT 2026-11-11T00:00:00');
        Http::assertSent(fn (Request $request): bool => self::queryOf($request) === 'lastEdited GE 2026-11-11T00:00:00 AND lastEdited LT 2026-11-21T00:00:00');
        Http::assertSent(fn (Request $request): bool => self::queryOf($request) === 'lastEdited GE 2026-11-21T00:00:00 AND lastEdited LT 2026-11-24T00:00:00');
    }

    public function test_an_empty_result_is_an_empty_last_page(): void
    {
        Http::fake([self::QUERY => self::xml(self::fixture('empty.xml'))]);

        $page = $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-11-22'));

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_response_that_is_not_a_result_list_fails(): void
    {
        Http::fake([self::QUERY => Http::response('<html><body>Login</body></html>', 200, ['Content-Type' => 'text/html'])]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-11-22'));
    }

    public function test_a_server_error_is_retried(): void
    {
        Http::fake([self::QUERY => Http::sequence()
            ->push('', 500)
            ->push(self::fixture('list.xml'), 200, ['Content-Type' => 'application/xml'])]);

        $page = $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-11-20'));

        $this->assertCount(2, $page->studies);
        Http::assertSentCount(2);
    }

    public function test_an_import_creates_the_studies_and_a_repeated_import_leaves_them_unchanged(): void
    {
        Http::fake([self::QUERY => self::xml(self::fixture('list.xml'))]);
        $options = new ImportOptions(updatedSince: CarbonImmutable::parse('2026-11-20'), source: IsrctnSource::KEY);

        $first = $this->app->make(ClinicalTrialImporter::class)->import($options);

        $this->assertSame(ImportRunStatus::Succeeded, $first->status);
        $this->assertSame(2, $first->studies_created);
        $trial = ClinicalTrial::query()->where('source', IsrctnSource::KEY)->where('source_id', 'ISRCTN49306926')->sole();
        $this->assertSame('COMPLETED', $trial->overall_status);
        $this->assertSame('xml', $trial->raw_format);
        $this->assertSame('Critical illness', $trial->conditions()->value('condition'));

        $second = $this->app->make(ClinicalTrialImporter::class)->import($options);

        $this->assertSame(ImportRunStatus::Succeeded, $second->status);
        $this->assertSame(0, $second->studies_created);
        $this->assertSame(2, $second->studies_unchanged);
        $this->assertSame(2, ClinicalTrial::query()->where('source', IsrctnSource::KEY)->count());
    }
}
