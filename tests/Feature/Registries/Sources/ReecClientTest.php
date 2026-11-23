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
use App\Services\Registries\Sources\Reec\ReecSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class ReecClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/reec/';

    private const string BASE = 'https://reec.aemps.es/reec-services/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00'));
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 10, 'max_sleep_ms' => 100],
            'registries.options.reec.full_import_from' => '2026-06-01',
            'registries.options.reec.window_days' => 30,
            'registries.sources' => [
                ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
                ReecSource::KEY => ReecSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(SourceRegistry::class)->get(ReecSource::KEY)->client();
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
     * Fakes the service: the June window lists the two fixture studies, every other window is
     * empty. The first `$failures` listing requests answer HTTP 503.
     */
    private function fakeService(string $listing = 'estudios.json', int $failures = 0): void
    {
        Http::fake(function (Request $request) use ($listing, &$failures) {
            $url = strtok($request->url(), '?');
            $json = ['Content-Type' => 'application/json'];

            if ($url === self::BASE.'estudios' && $failures > 0) {
                $failures--;

                return Http::response('Service unavailable', 503);
            }

            return match (true) {
                $url === self::BASE.'estudios' => Http::response(
                    self::fixture(self::queryOf($request)['fechadesde'] === '01/06/2026' ? $listing : 'estudios_empty.json'),
                    200,
                    $json,
                ),
                str_starts_with($url, self::BASE.'json/detalle/') => Http::response(self::fixture('detalle_'.basename($url).'.json'), 200, $json),
                $url === self::BASE.'busquedaestudios' => Http::response(
                    self::fixture(self::queryOf($request)['eudract'] === '2026-525328-10-00' ? 'busqueda_2026-525328-10-00.json' : 'busqueda_empty.json'),
                    200,
                    $json,
                ),
                default => Http::response('', 404),
            };
        });
    }

    public function test_studies_of_a_registration_window_are_read_in_pages(): void
    {
        $this->fakeService();

        $first = $this->client()->fetchPage(1);
        $this->assertSame(['2026-525328-10-00'], array_column($first->studies, 'identificador'));
        $this->assertSame('2', $first->studies[0]['busqueda']['estado']);
        $this->assertSame('2026-06-01|1', $first->nextPageToken);

        $second = $this->client()->fetchPage(1, $first->nextPageToken);
        $this->assertSame(['2025-525150-19-01'], array_column($second->studies, 'identificador'));
        $this->assertNull($second->studies[0]['busqueda']);
        $this->assertSame('2026-07-01|0', $second->nextPageToken);

        // The remaining windows are empty: they are skipped up to today.
        $third = $this->client()->fetchPage(1, $second->nextPageToken);
        $this->assertSame([], $third->studies);
        $this->assertNull($third->nextPageToken);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), self::BASE.'estudios')
            && self::queryOf($request) === ['xml' => 'false', 'fechadesde' => '01/06/2026', 'fechahasta' => '30/06/2026']);
    }

    public function test_an_empty_listing_returns_no_studies(): void
    {
        $this->fakeService('estudios_empty.json');

        $page = $this->client()->fetchPage(10);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_an_incremental_run_re_reads_the_look_back_period(): void
    {
        $this->fakeService();
        config(['registries.options.reec.lookback_days' => 90]);
        $this->app->forgetInstance(SourceRegistry::class);

        $this->client()->fetchPage(10, null, CarbonImmutable::parse('2026-11-20'));

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), self::BASE.'estudios')
            && self::queryOf($request)['fechadesde'] === '22/08/2026');
    }

    public function test_a_changed_response_format_fails_the_request(): void
    {
        Http::fake([self::BASE.'estudios*' => Http::response('{"studies":[]}', 200, ['Content-Type' => 'application/json'])]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(10);
    }

    public function test_a_detail_that_is_not_the_study_fails_the_request(): void
    {
        Http::fake([
            self::BASE.'estudios*' => Http::response(self::fixture('estudios.json'), 200, ['Content-Type' => 'application/json']),
            self::BASE.'json/detalle/*' => Http::response('<html>error</html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(10);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fakeService(failures: 1);

        $page = $this->client()->fetchPage(10);

        $this->assertCount(2, $page->studies);
        Http::assertSentCount(2 + 2 * 2); // the failed listing and its retry, then a detail and a search per study
    }

    public function test_an_import_creates_the_studies_and_an_identical_import_leaves_them_unchanged(): void
    {
        $this->fakeService();
        $importer = $this->app->make(ClinicalTrialImporter::class);
        $options = new ImportOptions(pageSize: 10, full: true, source: ReecSource::KEY);

        $first = $importer->import($options);
        $this->assertSame(2, $first->studies_created, (string) $first->error);

        $trial = ClinicalTrial::query()->where('source', ReecSource::KEY)->where('source_id', '2026-525328-10-00')->sole();
        $this->assertSame('RECRUITING', $trial->overall_status);
        $this->assertSame(['2026-525328-10-00'], $trial->secondaryIds()->pluck('secondary_id')->all());
        $this->assertStringNotContainsString('"investigador"', (string) $trial->raw_document);

        $second = $importer->import($options);
        $this->assertSame(2, $second->studies_unchanged, (string) $second->error);
        $this->assertSame(2, ClinicalTrial::query()->where('source', ReecSource::KEY)->count());
    }
}
