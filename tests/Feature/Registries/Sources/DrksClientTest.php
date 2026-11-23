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
use App\Services\Registries\Sources\Drks\DrksSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class DrksClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/drks/';

    private const string DUMP = 'drks.de/search/en/download/all-json';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 10, 'max_sleep_ms' => 100],
        ]);
    }

    /** The export as DRKS serves it: a ZIP with the JSON array and small text files. */
    private static function export(string $json): string
    {
        $path = tempnam(sys_get_temp_dir(), 'drks-zip');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('DRKS_all_20261123.json', $json);
        $zip->addFromString('Terms_of_Use.txt', 'By downloading this DRKS export I accept the terms of use.');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private static function fixtureExport(string $name = 'records.json'): string
    {
        return self::export((string) file_get_contents(self::FIXTURES.$name));
    }

    /**
     * @param  mixed  $dump  a response or sequence for the export URL
     */
    private function fakeDrks(mixed $dump): void
    {
        Http::fake([
            'drks.de/robots.txt' => Http::response("User-agent: *\nDisallow: /download/\nCrawl-delay: 10\n"),
            self::DUMP => $dump,
        ]);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(DrksSource::class)->client();
    }

    private function exportRequests(): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), self::DUMP))->count();
    }

    /**
     * @param  list<array<string, mixed>>  $studies
     * @return list<string>
     */
    private static function ids(array $studies): array
    {
        return array_column($studies, 'drksId');
    }

    public function test_open_licensed_records_are_returned_page_by_page_from_one_download(): void
    {
        $this->fakeDrks(Http::response(self::fixtureExport(), 200, ['Content-Type' => 'application/zip']));
        $client = $this->client();

        $first = $client->fetchPage(2);
        $this->assertSame(['DRKS00041448', 'DRKS00034741'], self::ids($first->studies));
        $this->assertTrue($first->hasNextPage());

        // DRKS00012345 (registered 2019, last updated 2024) is not under CC BY 4.0: skipped.
        $second = $client->fetchPage(2, $first->nextPageToken);
        $this->assertSame(['DRKS00021160', 'DRKS00040000'], self::ids($second->studies));

        $last = $client->fetchPage(2, $second->nextPageToken);
        $this->assertSame([], $last->studies);
        $this->assertFalse($last->hasNextPage());

        $this->assertSame(1, $this->exportRequests()); // downloaded once for every page
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), self::DUMP)
            && str_contains($request->header('User-Agent')[0], 'https://watcher.example/contact'));
    }

    public function test_incremental_runs_keep_records_updated_since_the_last_run(): void
    {
        $this->fakeDrks(Http::response(self::fixtureExport()));

        $page = $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-09-01'));

        $this->assertSame(['DRKS00041448', 'DRKS00021160'], self::ids($page->studies));
        $this->assertNull($page->nextPageToken);
    }

    public function test_an_empty_export_returns_no_studies(): void
    {
        $this->fakeDrks(Http::response(self::fixtureExport('empty.json')));

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_response_that_is_not_the_export_is_rejected(): void
    {
        $this->fakeDrks(Http::response('<html><body>Maintenance</body></html>', 200, ['Content-Type' => 'text/html']));

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_export_with_a_changed_layout_is_rejected(): void
    {
        $this->fakeDrks(Http::response(self::export('{"trials": []}')));

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fakeDrks(Http::sequence()
            ->push('', 500)
            ->push(self::fixtureExport(), 200, ['Content-Type' => 'application/zip']));

        $page = $this->client()->fetchPage(100);

        $this->assertCount(4, $page->studies);
        $this->assertSame(2, $this->exportRequests());
    }

    public function test_an_import_creates_the_studies_and_a_repeated_import_leaves_them_unchanged(): void
    {
        config(['registries.sources' => [
            ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
            DrksSource::KEY => DrksSource::class,
        ]]);
        $this->app->forgetInstance(SourceRegistry::class);
        $this->fakeDrks(Http::response(self::fixtureExport(), 200, ['Content-Type' => 'application/zip', 'ETag' => '"20261123"']));
        $importer = $this->app->make(ClinicalTrialImporter::class);

        $first = $importer->import(new ImportOptions(full: true, source: DrksSource::KEY));

        $this->assertSame(4, $first->studies_created);
        $this->assertSame(0, $first->studies_failed);
        $this->assertSame(4, ClinicalTrial::query()->where('source', DrksSource::KEY)->count());
        $this->assertFalse(ClinicalTrial::query()->where('source_id', 'DRKS00012345')->exists());

        $trial = ClinicalTrial::query()->where('source', DrksSource::KEY)->where('source_id', 'DRKS00034741')->sole();
        $this->assertSame('RECRUITING', $trial->overall_status);
        $this->assertSame(['NCT07290712'], $trial->secondaryIds()->pluck('secondary_id')->all());
        $this->assertSame('json', $trial->raw_format);
        $this->assertStringNotContainsString('"email"', (string) $trial->raw_document);

        $second = $importer->import(new ImportOptions(full: true, source: DrksSource::KEY));

        $this->assertSame(0, $second->studies_created);
        $this->assertSame(0, $second->studies_updated);
        $this->assertSame(4, $second->studies_unchanged);
        $this->assertSame(4, ClinicalTrial::query()->where('source', DrksSource::KEY)->count());
    }
}
