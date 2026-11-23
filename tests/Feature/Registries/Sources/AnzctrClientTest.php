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
use App\Services\Registries\Sources\Anzctr\AnzctrSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class AnzctrClientTest extends TestCase
{
    use RefreshDatabase;

    private const array HTML = ['Content-Type' => 'text/html; charset=utf-8'];

    private const string SEARCH = 'www.anzctr.org.au/TrialSearch.aspx';

    private const string TRIAL = 'www.anzctr.org.au/Trial/Registration/TrialReview.aspx?ACTRN=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 100, 'max_sleep_ms' => 1000],
            'registries.permissions.anzctr' => true,
        ]);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/registries/anzctr/'.$name));
    }

    /**
     * @param  array<string, mixed>  $responses
     */
    private function fake(array $responses): void
    {
        // ANZCTR publishes no robots.txt.
        Http::fake(['www.anzctr.org.au/robots.txt' => Http::response('Not found', 404)] + $responses);
    }

    /**
     * @return array<string, mixed>
     */
    private static function listing(): array
    {
        return [
            self::SEARCH => Http::response(self::fixture('synthetic_search.html'), 200, self::HTML),
            self::TRIAL.'12622000561785' => Http::response(self::fixture('record_ACTRN12622000561785.html'), 200, self::HTML),
            self::TRIAL.'12625000123456' => Http::response(self::fixture('synthetic_record_minimal.html'), 200, self::HTML),
        ];
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(AnzctrSource::class)->client();
    }

    public function test_listed_trials_are_returned_with_their_pages_and_the_token_moves_through_the_listing(): void
    {
        $this->fake(self::listing());

        $first = $this->client()->fetchPage(1);

        $this->assertSame(['ACTRN12622000561785'], array_column($first->studies, 'id'));
        $this->assertSame('1', $first->nextPageToken);
        $this->assertSame('https://'.self::TRIAL.'12622000561785', $first->studies[0]['url']);
        $this->assertSame('2026-08-25', $first->studies[0]['updated_on']);
        $this->assertStringContainsString('_CXPHONE">Redacted</span>', $first->studies[0]['html']);

        $last = $this->client()->fetchPage(1, '1');

        $this->assertSame(['ACTRN12625000123456'], array_column($last->studies, 'id'));
        $this->assertNull($last->nextPageToken);
    }

    public function test_the_listing_url_is_configurable(): void
    {
        config(['registries.options.anzctr.listing_url' => 'https://www.anzctr.org.au/TrialSearch.aspx?searchTxt=cancer']);
        $this->fake([self::SEARCH.'?searchTxt=cancer' => Http::response(self::fixture('synthetic_search_empty.html'), 200, self::HTML)]);

        $this->assertSame([], $this->client()->fetchPage(100)->studies);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'searchTxt=cancer'));
    }

    public function test_an_incremental_run_keeps_trials_updated_since_the_cursor(): void
    {
        $this->fake(self::listing());

        $page = $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-09-01'));

        // The record updated on 2026-08-25 is left out; the one without an update date is kept.
        $this->assertSame(['ACTRN12625000123456'], array_column($page->studies, 'id'));
        $this->assertNull($page->nextPageToken);
    }

    public function test_an_empty_listing_ends_the_run(): void
    {
        $this->fake([self::SEARCH => Http::response(self::fixture('synthetic_search_empty.html'), 200, self::HTML)]);

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_page_that_is_not_an_anzctr_page_fails(): void
    {
        $this->fake([self::SEARCH => Http::response('<html><head><title>Error</title></head><body></body></html>', 200, self::HTML)]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_trial_page_without_a_record_fails(): void
    {
        $this->fake([
            self::SEARCH => Http::response(self::fixture('synthetic_search.html'), 200, self::HTML),
            self::TRIAL.'*' => Http::response('<html><head><title>ANZCTR - Error</title></head><body>Error</body></html>', 200, self::HTML),
        ]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fake([
            self::SEARCH => Http::sequence()
                ->push('', 500)
                ->push(self::fixture('synthetic_search_empty.html'), 200, self::HTML),
        ]);

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        Http::assertSentCount(3); // robots.txt, the failed attempt and the retry
    }

    public function test_without_express_approval_the_import_fails_before_any_request(): void
    {
        config(['registries.permissions.anzctr' => false]);
        $this->registerSource();
        $this->fake(self::listing());

        $run = $this->app->make(ClinicalTrialImporter::class)->import(new ImportOptions(full: true, source: AnzctrSource::KEY));

        $this->assertSame(ImportRunStatus::Failed, $run->status);
        $this->assertStringContainsString('not allowed by its legal review', (string) $run->error);
        Http::assertNothingSent();
        $this->assertSame(0, ClinicalTrial::query()->where('source', AnzctrSource::KEY)->count());
    }

    public function test_an_identical_second_import_leaves_the_studies_unchanged(): void
    {
        $this->registerSource();
        $this->fake(self::listing());
        $import = fn () => $this->app->make(ClinicalTrialImporter::class)->import(new ImportOptions(full: true, source: AnzctrSource::KEY));

        $first = $import();

        $this->assertSame(ImportRunStatus::Succeeded, $first->status);
        $this->assertSame(2, $first->studies_created);
        $trial = ClinicalTrial::query()->where('source', AnzctrSource::KEY)->where('source_id', 'ACTRN12622000561785')->sole();
        $this->assertSame('RECRUITING', $trial->overall_status);
        $this->assertSame('html', $trial->raw_format);
        $this->assertStringNotContainsString('0413278336', (string) $trial->raw_document);
        $minimal = ClinicalTrial::query()->where('source', AnzctrSource::KEY)->where('source_id', 'ACTRN12625000123456')->sole();
        $this->assertSame(['NCT01234567', 'Sponsor protocol HIP-01', 'U1111-1234-5678'], $minimal->secondaryIds()->pluck('secondary_id')->all());

        $second = $import();

        $this->assertSame(ImportRunStatus::Succeeded, $second->status);
        $this->assertSame(2, $second->studies_unchanged);
        $this->assertSame(0, $second->studies_created + $second->studies_updated);
        $this->assertSame(2, ClinicalTrial::query()->where('source', AnzctrSource::KEY)->count());
    }

    private function registerSource(): void
    {
        config(['registries.sources' => [
            ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
            AnzctrSource::KEY => AnzctrSource::class,
        ]]);
        $this->app->forgetInstance(SourceRegistry::class);
    }
}
