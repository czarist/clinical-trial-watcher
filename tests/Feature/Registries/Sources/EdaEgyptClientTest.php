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
use App\Services\Registries\Parsing\PdfTextExtractor;
use App\Services\Registries\SourceRegistry;
use App\Services\Registries\Sources\EdaEgypt\EdaEgyptClient;
use App\Services\Registries\Sources\EdaEgypt\EdaEgyptSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class EdaEgyptClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/eda_egypt/';

    private const string PDF_URL = 'https://www.edaegypt.gov.eg/media/gmlkwm0q/ct-protocols-registry-oct-final-14-10-2026.pdf';

    /** Text the fake extractor returns for any PDF. */
    private string $pdfText = '';

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
                EdaEgyptSource::KEY => EdaEgyptSource::class,
            ],
        ]);
        $this->pdfText = (string) file_get_contents(self::FIXTURES.'registry-2026-07-07.txt');
        $test = $this;
        $this->app->instance(PdfTextExtractor::class, new class($test) implements PdfTextExtractor
        {
            public function __construct(private readonly EdaEgyptClientTest $test) {}

            public function extract(string $path): string
            {
                return $this->test->pdfText();
            }
        });
        $this->app->forgetInstance(SourceRegistry::class);
    }

    public function pdfText(): string
    {
        return $this->pdfText;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeRegistry(array $overrides = []): void
    {
        Http::fake($overrides + [
            'www.edaegypt.gov.eg/robots.txt' => Http::response('Not found', 404),
            'www.edaegypt.gov.eg/en/*' => Http::response((string) file_get_contents(self::FIXTURES.'landing.html'), 200, ['Content-Type' => 'text/html; charset=utf-8']),
            'www.edaegypt.gov.eg/media/*' => Http::response('%PDF-1.7 recorded edition', 200, ['Content-Type' => 'application/pdf']),
        ]);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(SourceRegistry::class)->get(EdaEgyptSource::KEY)->client();
    }

    public function test_the_newest_edition_is_read_and_paged(): void
    {
        $this->fakeRegistry();
        $client = $this->client();

        $first = $client->fetchPage(20);
        $second = $client->fetchPage(20, $first->nextPageToken);

        $this->assertInstanceOf(EdaEgyptClient::class, $client);
        $this->assertCount(20, $first->studies);
        $this->assertSame('20', $first->nextPageToken);
        $this->assertCount(6, $second->studies);
        $this->assertNull($second->nextPageToken);
        $this->assertSame('M15-991', $first->studies[0]['study_code']);
        $this->assertSame('07/07/2026', $first->studies[0]['edition']);
        $this->assertSame(self::PDF_URL, $first->studies[0]['url']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::PDF_URL);
        // robots.txt, landing page and PDF, once for both pages.
        Http::assertSentCount(3);
    }

    public function test_a_configured_pdf_url_skips_the_landing_page(): void
    {
        config(['registries.options.eda_egypt.pdf_url' => 'https://www.edaegypt.gov.eg/media/1gwbk0sz/ct-protocols-registry-july-2026.pdf']);
        $this->fakeRegistry();

        $this->assertCount(26, $this->client()->fetchPage(100)->studies);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/en/'));
    }

    public function test_an_edition_without_rows_gives_an_empty_page(): void
    {
        $this->pdfText = (string) preg_replace('/^\s+\d+\.\s.*$/m', '', explode("\f", $this->pdfText)[0]);
        $this->pdfText = implode("\n", array_slice(explode("\n", $this->pdfText), 0, 12));
        $this->fakeRegistry();

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_landing_page_without_editions_fails(): void
    {
        $this->fakeRegistry(['www.edaegypt.gov.eg/en/*' => Http::response('<html><body><div class="news-details-description"></div></body></html>')]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_file_that_is_not_a_pdf_fails(): void
    {
        $this->fakeRegistry(['www.edaegypt.gov.eg/media/*' => Http::response('<html>moved</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_pdf_without_the_registry_table_fails(): void
    {
        $this->pdfText = "Periodic list of approved products\nNothing tabular here\n";
        $this->fakeRegistry();

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fakeRegistry(['www.edaegypt.gov.eg/media/*' => Http::sequence()
            ->push('busy', 503)
            ->push('%PDF-1.7 recorded edition', 200, ['Content-Type' => 'application/pdf'])]);

        $this->assertCount(26, $this->client()->fetchPage(100)->studies);
    }

    public function test_an_import_creates_the_studies_and_a_second_identical_import_changes_nothing(): void
    {
        $this->fakeRegistry();
        $importer = $this->app->make(ClinicalTrialImporter::class);

        $first = $importer->import(new ImportOptions(full: true, source: EdaEgyptSource::KEY));

        $this->assertSame(ImportRunStatus::Succeeded, $first->status);
        $this->assertSame(26, ClinicalTrial::query()->where('source', EdaEgyptSource::KEY)->count());
        $trial = ClinicalTrial::query()->where('source', EdaEgyptSource::KEY)->where('source_id', 'M15-991')->sole();
        $this->assertSame('COMPLETED', $trial->overall_status);
        $this->assertSame('pdf', $trial->raw_format);
        $this->assertSame(5, $trial->locations()->count());

        $second = $importer->import(new ImportOptions(full: true, source: EdaEgyptSource::KEY));

        $this->assertSame(ImportRunStatus::Succeeded, $second->status);
        $this->assertSame(26, $second->studies_unchanged);
        $this->assertSame(26, ClinicalTrial::query()->where('source', EdaEgyptSource::KEY)->count());
    }
}
