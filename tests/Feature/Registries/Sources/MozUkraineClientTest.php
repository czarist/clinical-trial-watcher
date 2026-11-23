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
use App\Services\Registries\Sources\MozUkraine\MozUkraineSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class MozUkraineClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/moz_ukraine/';

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
            'registries.options.moz_ukraine.max_pages' => 2,
            'registries.sources' => [
                ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
                MozUkraineSource::KEY => MozUkraineSource::class,
            ],
        ]);
        $this->pdfText = self::fixture('annex_1807.txt');
        $test = $this;
        $this->app->instance(PdfTextExtractor::class, new class($test) implements PdfTextExtractor
        {
            public function __construct(private readonly MozUkraineClientTest $test) {}

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

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeMoz(array $overrides = []): void
    {
        Http::fake($overrides + [
            'moz.gov.ua/robots.txt' => Http::response("User-agent: *\nDisallow:"),
            'moz.gov.ua/uk/nakazi-moz*' => fn (Request $request) => Http::response(
                self::fixture(str_contains($request->url(), 'page=1') ? 'listing_page1.html' : 'listing_empty.html'),
                200,
                ['Content-Type' => 'text/html; charset=UTF-8'],
            ),
            'moz.gov.ua/uk/decrees/*' => Http::response(self::fixture('decree_1807.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8']),
            'moz.gov.ua/storage/*' => Http::response('%PDF-1.7 recorded annex', 200, ['Content-Type' => 'application/pdf']),
        ]);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(SourceRegistry::class)->get(MozUkraineSource::KEY)->client();
    }

    public function test_authorised_trials_of_clinical_trial_orders_are_returned(): void
    {
        $this->fakeMoz();

        $page = $this->client()->fetchPage(100);

        $this->assertCount(2, $page->studies);
        $this->assertSame('2', $page->nextPageToken);
        $this->assertSame('1807', $page->studies[0]['order_number']);
        $this->assertSame('2026-11-13', $page->studies[0]['order_date']);
        $this->assertStringContainsString('1838-pro-provedennya-klinichnogo', $page->studies[0]['order_url']);
        // Only the clinical trial order of the ten listed is opened.
        Http::assertSentCount(4);
    }

    public function test_an_empty_listing_page_ends_the_run(): void
    {
        $this->fakeMoz();

        $page = $this->client()->fetchPage(100, '2');

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_orders_older_than_the_last_run_stop_the_listing(): void
    {
        $this->fakeMoz();

        $page = $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-12-01'));

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/decrees/'));
    }

    public function test_a_changed_listing_fails(): void
    {
        $this->fakeMoz(['moz.gov.ua/uk/nakazi-moz*' => Http::response('<html><body><p>Технічні роботи</p></body></html>')]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_annex_that_is_not_a_pdf_fails(): void
    {
        $this->fakeMoz(['moz.gov.ua/storage/*' => Http::response('<html>moved</html>', 200, ['Content-Type' => 'text/html'])]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_annex_whose_text_has_no_annexes_fails(): void
    {
        $this->pdfText = "\u{FFFD}\u{FFFD} 1807 \u{FFFD}\u{FFFD}\n";
        $this->fakeMoz();

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fakeMoz(['moz.gov.ua/uk/decrees/*' => Http::sequence()
            ->push('busy', 502)
            ->push(self::fixture('decree_1807.html'), 200, ['Content-Type' => 'text/html; charset=UTF-8'])]);

        $this->assertCount(2, $this->client()->fetchPage(100)->studies);
    }

    public function test_an_import_creates_the_trials_and_a_second_identical_import_changes_nothing(): void
    {
        $this->fakeMoz();
        $importer = $this->app->make(ClinicalTrialImporter::class);

        $first = $importer->import(new ImportOptions(full: true, source: MozUkraineSource::KEY));

        $this->assertSame(ImportRunStatus::Succeeded, $first->status);
        $this->assertSame(['230LE305', 'D8890C00001'], ClinicalTrial::query()->where('source', MozUkraineSource::KEY)->orderBy('source_id')->pluck('source_id')->all());
        $trial = ClinicalTrial::query()->where('source', MozUkraineSource::KEY)->where('source_id', 'D8890C00001')->sole();
        $this->assertSame('PHASE3', $trial->phase);
        $this->assertSame('pdf', $trial->raw_format);
        $this->assertSame(4, $trial->locations()->count());

        $second = $importer->import(new ImportOptions(full: true, source: MozUkraineSource::KEY));

        $this->assertSame(ImportRunStatus::Succeeded, $second->status);
        $this->assertSame(2, $second->studies_unchanged);
        $this->assertSame(2, ClinicalTrial::query()->where('source', MozUkraineSource::KEY)->count());
    }
}
