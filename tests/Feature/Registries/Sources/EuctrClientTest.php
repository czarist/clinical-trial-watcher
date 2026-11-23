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
use App\Services\Registries\Sources\Euctr\EuctrSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class EuctrClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/euctr/';

    private const string HOST = 'https://www.clinicaltrialsregister.eu';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 10, 'max_sleep_ms' => 100],
            // One trial per results page, so the fixtures span several pages.
            'registries.options.euctr.trials_per_page' => 1,
            'registries.sources' => [
                ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
                EuctrSource::KEY => EuctrSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(SourceRegistry::class)->get(EuctrSource::KEY)->client();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }

    /**
     * @return array<string, string>
     */
    private static function queryOf(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * Fakes the register: robots.txt is absent (404) and each download page serves a fixture.
     * The first `$failures` downloads answer HTTP 500.
     *
     * @param  array<int, string>  $pages
     */
    private function fakeRegister(array $pages = [1 => 'download_full_two_states.txt', 2 => 'download_full_2019-003781-41.txt'], int $failures = 0): void
    {
        Http::fake(function (Request $request) use ($pages, &$failures) {
            $url = strtok($request->url(), '?');

            if ($url === self::HOST.'/robots.txt') {
                return Http::response('', 404);
            }

            if ($url !== self::HOST.'/ctr-search/rest/download/full') {
                return Http::response('', 404);
            }

            if ($failures > 0) {
                $failures--;

                return Http::response('Internal error', 500);
            }

            $page = (int) (self::queryOf($request)['page'] ?? 1);

            return Http::response(self::fixture($pages[$page] ?? 'download_full_empty.txt'), 200, ['Content-Type' => 'application/octet-stream']);
        });
    }

    public function test_download_pages_are_followed_and_member_states_grouped(): void
    {
        $this->fakeRegister();

        $first = $this->client()->fetchPage(100);
        $this->assertSame(['2015-002555-10'], array_column($first->studies, 'eudract'));
        $this->assertCount(2, $first->studies[0]['blocks']);
        $this->assertSame('2|2015-002555-10', $first->nextPageToken);

        $second = $this->client()->fetchPage(100, $first->nextPageToken);
        $this->assertSame(['2019-003781-41'], array_column($second->studies, 'eudract'));
        $this->assertSame('3|2019-003781-41', $second->nextPageToken);

        $third = $this->client()->fetchPage(100, $second->nextPageToken);
        $this->assertSame([], $third->studies);
        $this->assertNull($third->nextPageToken);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/rest/download/full')
            && self::queryOf($request) === ['query' => '', 'page' => '1', 'mode' => 'current_page']);
    }

    public function test_a_repeated_last_page_ends_the_walk(): void
    {
        $this->fakeRegister([1 => 'download_full_2015-002555-10.txt', 2 => 'download_full_2015-002555-10.txt']);

        $first = $this->client()->fetchPage(100);
        $second = $this->client()->fetchPage(100, $first->nextPageToken);

        $this->assertSame([], $second->studies);
        $this->assertNull($second->nextPageToken);
    }

    public function test_an_empty_result_returns_no_studies(): void
    {
        $this->fakeRegister([1 => 'download_full_empty.txt']);

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_an_incremental_run_filters_on_the_date_first_entered(): void
    {
        $this->fakeRegister([1 => 'download_full_2019-003781-41.txt']);

        $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-11-01'));

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/rest/download/full')
            && (self::queryOf($request)['dateFrom'] ?? null) === '2026-11-01');
    }

    public function test_an_html_page_instead_of_the_download_fails_the_request(): void
    {
        Http::fake([
            self::HOST.'/robots.txt' => Http::response('', 404),
            self::HOST.'/ctr-search/rest/download/full*' => Http::response('<!DOCTYPE html><html><body>Maintenance</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fakeRegister(failures: 1);

        $page = $this->client()->fetchPage(100);

        $this->assertSame(['2015-002555-10'], array_column($page->studies, 'eudract'));
    }

    public function test_an_import_creates_the_trials_and_an_identical_import_leaves_them_unchanged(): void
    {
        $this->fakeRegister();
        $importer = $this->app->make(ClinicalTrialImporter::class);
        $options = new ImportOptions(full: true, source: EuctrSource::KEY);

        $first = $importer->import($options);
        $this->assertSame(2, $first->studies_created, (string) $first->error);

        $trial = ClinicalTrial::query()->where('source', EuctrSource::KEY)->where('source_id', '2015-002555-10')->sole();
        $this->assertSame('COMPLETED', $trial->overall_status);
        $this->assertSame('txt', $trial->raw_format);
        $this->assertSame(['NCT02660489'], $trial->secondaryIds()->pluck('secondary_id')->all());
        $this->assertSame(2, $trial->locations()->count());

        $second = $importer->import($options);
        $this->assertSame(2, $second->studies_unchanged, (string) $second->error);
        $this->assertSame(2, ClinicalTrial::query()->where('source', EuctrSource::KEY)->count());
    }
}
