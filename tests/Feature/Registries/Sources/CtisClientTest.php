<?php

declare(strict_types=1);

namespace Tests\Feature\Registries\Sources;

use App\Exceptions\RegistryRequestFailed;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Import\ClinicalTrialImporter;
use App\Services\ClinicalTrials\Import\ImportOptions;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\ClinicalTrialsGovSource;
use App\Services\Registries\SourceRegistry;
use App\Services\Registries\Sources\Ctis\CtisSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class CtisClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/ctis/';

    private const string SEARCH = 'https://euclinicaltrials.eu/ctis-public-api/search';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 10, 'max_sleep_ms' => 100],
            'registries.sources' => [
                ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
                CtisSource::KEY => CtisSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(SourceRegistry::class)->get(CtisSource::KEY)->client();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }

    /**
     * Fakes the portal: search pages by their page number, trials by their EU CT number.
     *
     * @param  array<int, string>  $searchPages  fixture of each search page
     */
    private function fakePortal(array $searchPages = [1 => 'search_page1.json', 2 => 'search_page2.json']): void
    {
        Http::fake(function (Request $request) use ($searchPages) {
            if ($request->method() === 'POST' && $request->url() === self::SEARCH) {
                $page = $request->data()['pagination']['page'] ?? 1;

                return isset($searchPages[$page])
                    ? Http::response(self::fixture($searchPages[$page]), 200, ['Content-Type' => 'application/json'])
                    : Http::response(self::fixture('search_empty.json'), 200, ['Content-Type' => 'application/json']);
            }

            if ($request->method() === 'GET' && preg_match('#/ctis-public-api/retrieve/([0-9-]+)$#', $request->url(), $match) === 1
                && is_file(self::FIXTURES."retrieve_{$match[1]}.json")) {
                return Http::response(self::fixture("retrieve_{$match[1]}.json"), 200, ['Content-Type' => 'application/json']);
            }

            return Http::response('', 404);
        });
    }

    public function test_search_pages_are_followed_and_each_trial_is_retrieved(): void
    {
        $this->fakePortal();

        $first = $this->client()->fetchPage(2);
        $this->assertSame(['2026-527959-25-00', '2023-505807-21-00'], array_column($first->studies, 'ctNumber'));
        $this->assertSame('21/11/2026', $first->studies[0]['searchResult']['lastUpdated']);
        $this->assertSame('2:2', $first->nextPageToken);

        $second = $this->client()->fetchPage(2, $first->nextPageToken);
        $this->assertSame(['2025-520887-17-00'], array_column($second->studies, 'ctNumber'));
        $this->assertNull($second->nextPageToken);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->data()['sort'] === ['property' => 'lastUpdated', 'direction' => 'DESC']
            && $request->data()['pagination'] === ['page' => 1, 'size' => 2]);
    }

    public function test_an_empty_search_returns_no_studies(): void
    {
        $this->fakePortal([1 => 'search_empty.json']);

        $page = $this->client()->fetchPage(50);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_an_incremental_run_stops_at_trials_last_updated_before_the_date(): void
    {
        $this->fakePortal();
        $since = CarbonImmutable::parse('2026-06-01');

        $first = $this->client()->fetchPage(2, null, $since);
        $this->assertCount(2, $first->studies);

        $second = $this->client()->fetchPage(2, $first->nextPageToken, $since);
        $this->assertSame([], $second->studies);
        $this->assertNull($second->nextPageToken);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/retrieve/2025-520887-17-00'));
    }

    public function test_a_changed_response_format_fails_the_request(): void
    {
        Http::fake([self::SEARCH => Http::response('{"items":[]}', 200, ['Content-Type' => 'application/json'])]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(10);
    }

    public function test_a_retrieved_document_that_is_not_the_trial_fails_the_request(): void
    {
        Http::fake(function (Request $request) {
            return $request->method() === 'POST'
                ? Http::response(self::fixture('search_page2.json'), 200, ['Content-Type' => 'application/json'])
                : Http::response('<html>maintenance</html>', 200, ['Content-Type' => 'text/html']);
        });

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(10);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $searches = 0;
        Http::fake(function (Request $request) use (&$searches) {
            if ($request->method() === 'POST') {
                return ++$searches === 1
                    ? Http::response('Bad gateway', 502)
                    : Http::response(self::fixture('search_page2.json'), 200, ['Content-Type' => 'application/json']);
            }

            return Http::response(self::fixture('retrieve_2025-520887-17-00.json'), 200, ['Content-Type' => 'application/json']);
        });

        $page = $this->client()->fetchPage(10);

        $this->assertSame(2, $searches);
        $this->assertCount(1, $page->studies);
    }

    public function test_a_trial_no_longer_published_is_skipped(): void
    {
        Http::fake(function (Request $request) {
            return $request->method() === 'POST'
                ? Http::response(self::fixture('search_page2.json'), 200, ['Content-Type' => 'application/json'])
                : Http::response('', 404);
        });

        $this->assertSame([], $this->client()->fetchPage(10)->studies);
    }

    public function test_an_import_creates_the_trials_and_an_identical_import_leaves_them_unchanged(): void
    {
        $this->fakePortal();
        $importer = $this->app->make(ClinicalTrialImporter::class);
        $options = new ImportOptions(pageSize: 2, full: true, source: CtisSource::KEY);

        $first = $importer->import($options);
        $this->assertSame(3, $first->studies_created, (string) $first->error);
        $this->assertSame(3, ClinicalTrial::query()->where('source', CtisSource::KEY)->count());

        $trial = ClinicalTrial::query()->where('source_id', '2023-505807-21-00')->sole();
        $this->assertSame('ACTIVE_NOT_RECRUITING', $trial->overall_status);
        $this->assertSame('json', $trial->raw_format);
        $this->assertStringNotContainsString('personInfo', (string) $trial->raw_document);

        $second = $importer->import($options);
        $this->assertSame(3, $second->studies_unchanged, (string) $second->error);
        $this->assertSame(0, $second->studies_created);
        $this->assertSame(3, ClinicalTrial::query()->where('source', CtisSource::KEY)->count());
    }
}
