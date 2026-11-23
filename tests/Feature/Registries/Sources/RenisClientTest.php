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
use App\Services\Registries\Sources\Renis\RenisSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class RenisClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/renis/';

    private const string DATASET = 'datos.salud.gob.ar/dataset/registro-nacional-de-investigaciones-en-salud-renis';

    private const string NEWEST = 'datos.salud.gob.ar/dataset/5b7b2efb-111a-48eb-9476-c5871f5f8f11/resource/54372b28-a8ff-4f2e-a09f-7869b307ce56/download/base-renis-_23-columnas_hasta-agosto-2026.xlsx';

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

    /**
     * @param  mixed  $workbook  a response or sequence for the newest workbook's URL
     */
    private function fakeRenis(mixed $workbook): void
    {
        Http::fake([
            'datos.salud.gob.ar/robots.txt' => Http::response("User-agent: *\nDisallow: /api/\nCrawl-Delay: 10\n"),
            self::DATASET => Http::response((string) file_get_contents(self::FIXTURES.'dataset.html'), 200, ['Content-Type' => 'text/html; charset=utf-8']),
            self::NEWEST => $workbook,
        ]);
    }

    private static function workbook(string $name = 'renis.xlsx'): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }

    /** A workbook whose header row is not the RENIS layout. */
    private static function otherWorkbook(): string
    {
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $path = tempnam(sys_get_temp_dir(), 'renis-xlsx');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="'.$ns.'"><sheets><sheet name="Hoja1" sheetId="1"/></sheets></workbook>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="'.$ns.'"><sheetData>'
            .'<row r="1"><c r="A1" t="inlineStr"><is><t>CODIGO</t></is></c><c r="B1" t="inlineStr"><is><t>NOMBRE</t></is></c></row>'
            .'<row r="2"><c r="A2"><v>1</v></c><c r="B2" t="inlineStr"><is><t>Estudio</t></is></c></row></sheetData></worksheet>');
        $zip->close();
        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(RenisSource::class)->client();
    }

    private function requestsTo(string $url): int
    {
        return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $url))->count();
    }

    public function test_the_newest_workbook_is_found_and_read_page_by_page_without_personal_data(): void
    {
        $this->fakeRenis(Http::response(self::workbook(), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']));
        $client = $this->client();

        $first = $client->fetchPage(3);
        $this->assertSame(['2', '5466', '362'], array_column($first->studies, 'ID_RENIS'));
        $this->assertSame('3', $first->nextPageToken);

        $second = $client->fetchPage(3, $first->nextPageToken);
        $this->assertSame(['1'], array_column($second->studies, 'ID_RENIS'));
        $this->assertNull($second->nextPageToken);

        foreach ([...$first->studies, ...$second->studies] as $row) {
            $this->assertArrayNotHasKey('MAIL', $row);
            $this->assertArrayNotHasKey('RESPONSABLE_REGISTRO', $row);
        }

        $this->assertSame(1, $this->requestsTo(self::NEWEST));
        $this->assertSame(0, $this->requestsTo('renis_actualizacion-_mayo-2025.xlsx'));
        $this->assertSame(0, $this->requestsTo('/api/'));
    }

    public function test_the_operator_can_set_the_workbook_url(): void
    {
        config(['registries.options.renis.file_url' => 'https://'.self::NEWEST]);
        $this->fakeRenis(Http::response(self::workbook()));

        $this->assertCount(4, $this->client()->fetchPage(100)->studies);
        $this->assertSame(0, $this->requestsTo(self::DATASET));
    }

    public function test_an_empty_workbook_returns_no_studies(): void
    {
        $this->fakeRenis(Http::response(self::workbook('renis-empty.xlsx')));

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_response_that_is_not_a_workbook_is_rejected(): void
    {
        $this->fakeRenis(Http::response('<html><body>Página no encontrada</body></html>', 200, ['Content-Type' => 'text/html']));

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_workbook_with_a_changed_layout_is_rejected(): void
    {
        $this->fakeRenis(Http::response(self::otherWorkbook()));

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_dataset_page_without_workbooks_is_rejected(): void
    {
        Http::fake([
            'datos.salud.gob.ar/robots.txt' => Http::response("User-agent: *\nCrawl-Delay: 10\n"),
            self::DATASET => Http::response('<html><body>Sin recursos</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fakeRenis(Http::sequence()->push('', 503)->push(self::workbook()));

        $this->assertCount(4, $this->client()->fetchPage(100)->studies);
        $this->assertSame(2, $this->requestsTo(self::NEWEST));
    }

    public function test_an_import_creates_the_studies_and_a_repeated_import_leaves_them_unchanged(): void
    {
        config(['registries.sources' => [
            ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
            RenisSource::KEY => RenisSource::class,
        ]]);
        $this->app->forgetInstance(SourceRegistry::class);
        $this->fakeRenis(Http::response(self::workbook()));
        $importer = $this->app->make(ClinicalTrialImporter::class);

        $first = $importer->import(new ImportOptions(full: true, source: RenisSource::KEY));

        $this->assertSame(4, $first->studies_created);
        $this->assertSame(0, $first->studies_failed);

        $trial = ClinicalTrial::query()->where('source', RenisSource::KEY)->where('source_id', '5466')->sole();
        $this->assertSame('RECRUITING', $trial->overall_status);
        $this->assertSame('PHASE3', $trial->phase);
        $this->assertSame(['NCT05757102'], $trial->secondaryIds()->pluck('secondary_id')->all());
        $this->assertSame(['Argentina'], $trial->locations()->pluck('country')->all());
        $this->assertStringNotContainsString('MAIL', (string) $trial->raw_document);

        $second = $importer->import(new ImportOptions(full: true, source: RenisSource::KEY));

        $this->assertSame(0, $second->studies_created);
        $this->assertSame(0, $second->studies_updated);
        $this->assertSame(4, $second->studies_unchanged);
        $this->assertSame(4, ClinicalTrial::query()->where('source', RenisSource::KEY)->count());
    }
}
