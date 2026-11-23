<?php

declare(strict_types=1);

namespace Tests\Feature\Registries\Sources;

use App\Enums\ImportRunStatus;
use App\Exceptions\RegistryRequestFailed;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Import\ClinicalTrialImporter;
use App\Services\ClinicalTrials\Import\ImportOptions;
use App\Services\Registries\ClinicalTrialsGovSource;
use App\Services\Registries\SourceRegistry;
use App\Services\Registries\Sources\Tfda\TfdaClient;
use App\Services\Registries\Sources\Tfda\TfdaSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class TfdaClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/tfda/';

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
                TfdaSource::KEY => TfdaSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private function fakeExport(string $body, int $status = 200): void
    {
        Http::fake([
            'data.fda.gov.tw/robots.txt' => Http::response('', 404),
            'data.fda.gov.tw/data/opendata/export/205/csv' => Http::response($body, $status, ['Content-Type' => 'application/zip']),
        ]);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }

    private function client(): TfdaClient
    {
        $client = $this->app->make(SourceRegistry::class)->get(TfdaSource::KEY)->client();
        $this->assertInstanceOf(TfdaClient::class, $client);

        return $client;
    }

    public function test_trials_are_paged_by_document_number(): void
    {
        $this->fakeExport(self::fixture('export_205.zip'));
        $client = $this->client();

        $first = $client->fetchPage(4);
        $second = $client->fetchPage(4, $first->nextPageToken);

        $this->assertCount(4, $first->studies);
        $this->assertSame('4', $first->nextPageToken);
        $this->assertCount(2, $second->studies);
        $this->assertNull($second->nextPageToken);
        $this->assertSame('1119014005', $first->studies[0]['document_number']);
        $this->assertCount(2, $first->studies[0]['rows']);
        // One download for both pages.
        Http::assertSentCount(2);
    }

    public function test_an_empty_export_has_no_trials(): void
    {
        $this->fakeExport(self::fixture('export_205_empty.zip'));

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_truncated_download_fails(): void
    {
        $zip = self::fixture('export_205.zip');
        $this->fakeExport(substr($zip, 0, intdiv(strlen($zip), 2)));

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_response_that_is_not_a_zip_fails(): void
    {
        $this->fakeExport('<html><body>Maintenance</body></html>');

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_changed_header_fails(): void
    {
        $this->fakeExport(self::fixture('export_205_changed_header.zip'));

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        Http::fake([
            'data.fda.gov.tw/robots.txt' => Http::response('', 404),
            'data.fda.gov.tw/data/opendata/export/205/csv' => Http::sequence()
                ->push('error', 500)
                ->push(self::fixture('export_205.zip'), 200, ['Content-Type' => 'application/zip']),
        ]);

        $this->assertCount(6, $this->client()->fetchPage(100)->studies);
    }

    public function test_the_export_url_can_be_configured(): void
    {
        config(['registries.options.tfda.url' => 'https://data.fda.gov.tw/data/opendata/export/205/csv?mirror=1']);
        Http::fake([
            'data.fda.gov.tw/robots.txt' => Http::response('', 404),
            'data.fda.gov.tw/data/opendata/export/205/csv?mirror=1' => Http::response(self::fixture('export_205.zip')),
        ]);

        $this->assertCount(6, $this->client()->fetchPage(100)->studies);
    }

    public function test_an_import_creates_the_trials_and_a_second_identical_import_changes_nothing(): void
    {
        $this->fakeExport(self::fixture('export_205.zip'));
        $importer = $this->app->make(ClinicalTrialImporter::class);

        $first = $importer->import(new ImportOptions(full: true, source: TfdaSource::KEY));

        $this->assertSame(ImportRunStatus::Succeeded, $first->status);
        $this->assertSame(6, ClinicalTrial::query()->where('source', TfdaSource::KEY)->count());
        $trial = ClinicalTrial::query()->where('source', TfdaSource::KEY)->where('source_id', '1119014005')->sole();
        $this->assertSame('CBAF312D2301', $trial->org_study_id);
        $this->assertSame('PHASE3', $trial->phase);
        $this->assertSame('csv', $trial->raw_format);
        $this->assertSame(2, $trial->conditions()->count());

        $second = $importer->import(new ImportOptions(full: true, source: TfdaSource::KEY));

        $this->assertSame(ImportRunStatus::Succeeded, $second->status);
        $this->assertSame(6, $second->studies_unchanged);
        $this->assertSame(6, ClinicalTrial::query()->where('source', TfdaSource::KEY)->count());
    }
}
