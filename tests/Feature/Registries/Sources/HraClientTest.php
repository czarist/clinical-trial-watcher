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
use App\Services\Registries\Sources\Hra\HraSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class HraClientTest extends TestCase
{
    use RefreshDatabase;

    private const array HTML = ['Content-Type' => 'text/html; charset=utf-8'];

    private const string LISTING = 'www.hra.nhs.uk/planning-and-improving-research/application-summaries/research-summaries/?page=';

    private const string SUMMARIES = 'www.hra.nhs.uk/planning-and-improving-research/application-summaries/research-summaries/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 100, 'max_sleep_ms' => 1000],
            'registries.permissions.hra' => true,
        ]);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/registries/hra/'.$name));
    }

    /**
     * @param  array<string, mixed>  $responses
     */
    private function fake(array $responses): void
    {
        // The HRA publishes no robots.txt.
        Http::fake(['www.hra.nhs.uk/robots.txt' => Http::response('Not found', 404)] + $responses);
    }

    /**
     * @return array<string, mixed>
     */
    private static function singleListing(): array
    {
        return [
            self::LISTING.'1*' => Http::response(self::fixture('listing_single.html'), 200, self::HTML),
            self::SUMMARIES.'phase-i-trial-qsc303813/' => Http::response(self::fixture('synthetic_detail_deferred.html'), 200, self::HTML),
            self::SUMMARIES.'phase-3-study-of-treprostinil-inhalation-powder-in-pah-palm-pah/' => Http::response(self::fixture('detail_palm-pah.html'), 200, self::HTML),
        ];
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(HraSource::class)->client();
    }

    public function test_listed_summaries_are_returned_with_their_pages_and_the_token_moves_through_the_listing(): void
    {
        $this->fake([
            self::LISTING.'1' => Http::response(self::fixture('listing_page1.html'), 200, self::HTML),
            self::SUMMARIES.'*' => Http::response(self::fixture('detail_palm-pah.html'), 200, self::HTML),
        ]);

        $first = $this->client()->fetchPage(5);

        $this->assertCount(5, $first->studies);
        $this->assertSame('1:5', $first->nextPageToken);
        $this->assertSame('IRAS1014302', $first->studies[0]['id']);
        $this->assertSame('https://'.self::SUMMARIES.'phase-i-trial-qsc303813/', $first->studies[0]['url']);
        $this->assertSame('2026-10-02', $first->studies[0]['rec_opinion_date']);
        $this->assertStringNotContainsString('insmed.com', $first->studies[0]['html']);

        $last = $this->client()->fetchPage(10, '1:15');

        $this->assertCount(5, $last->studies);
        $this->assertSame('2:0', $last->nextPageToken);
    }

    public function test_an_incremental_run_filters_by_rec_opinion_date_with_a_lookback(): void
    {
        $this->fake(self::singleListing());

        $page = $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-10-31'));

        $this->assertSame(['IRAS1014302', 'IRAS1013819'], array_column($page->studies, 'id'));
        $this->assertNull($page->nextPageToken);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'date_from=03%2F07%2F2026'));
    }

    public function test_an_empty_listing_ends_the_run(): void
    {
        $this->fake([self::LISTING.'9' => Http::response(self::fixture('synthetic_listing_empty.html'), 200, self::HTML)]);

        $page = $this->client()->fetchPage(100, '9:0');

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_changed_listing_fails_instead_of_returning_nothing(): void
    {
        $this->fake([self::LISTING.'1' => Http::response('<html><body><h1>New website</h1></body></html>', 200, self::HTML)]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_summary_page_without_a_summary_fails(): void
    {
        $this->fake([
            self::LISTING.'1' => Http::response(self::fixture('listing_single.html'), 200, self::HTML),
            self::SUMMARIES.'*' => Http::response('<html><body>Page not found</body></html>', 200, self::HTML),
        ]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fake([
            self::LISTING.'1' => Http::sequence()
                ->push('', 500)
                ->push(self::fixture('synthetic_listing_empty.html'), 200, self::HTML),
        ]);

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        Http::assertSentCount(3); // robots.txt, the failed attempt and the retry
    }

    public function test_without_written_permission_the_import_fails_before_any_request(): void
    {
        config(['registries.permissions.hra' => false]);
        $this->registerSource();
        $this->fake(self::singleListing());

        $run = $this->app->make(ClinicalTrialImporter::class)->import(new ImportOptions(full: true, source: HraSource::KEY));

        $this->assertSame(ImportRunStatus::Failed, $run->status);
        $this->assertStringContainsString('not allowed by its legal review', (string) $run->error);
        Http::assertNothingSent();
        $this->assertSame(0, ClinicalTrial::query()->where('source', HraSource::KEY)->count());
    }

    public function test_an_identical_second_import_leaves_the_studies_unchanged(): void
    {
        $this->registerSource();
        $this->fake(self::singleListing());
        $import = fn () => $this->app->make(ClinicalTrialImporter::class)->import(new ImportOptions(full: true, source: HraSource::KEY));

        $first = $import();

        $this->assertSame(ImportRunStatus::Succeeded, $first->status);
        $this->assertSame(2, $first->studies_created);
        $trial = ClinicalTrial::query()->where('source', HraSource::KEY)->where('source_id', 'IRAS1013819')->sole();
        $this->assertSame('Phase 3 Study of Treprostinil Inhalation Powder in PAH (PALM-PAH)', $trial->brief_title);
        $this->assertNull($trial->overall_status);
        $this->assertSame('html', $trial->raw_format);
        $this->assertSame(['IRAS1013819', '26/SC/0166'], $trial->secondaryIds()->pluck('secondary_id')->all());
        $this->assertStringNotContainsString('insmed.com', (string) $trial->raw_document);

        $second = $import();

        $this->assertSame(ImportRunStatus::Succeeded, $second->status);
        $this->assertSame(2, $second->studies_unchanged);
        $this->assertSame(0, $second->studies_created + $second->studies_updated);
        $this->assertSame(2, ClinicalTrial::query()->where('source', HraSource::KEY)->count());
    }

    private function registerSource(): void
    {
        config(['registries.sources' => [
            ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
            HraSource::KEY => HraSource::class,
        ]]);
        $this->app->forgetInstance(SourceRegistry::class);
    }
}
