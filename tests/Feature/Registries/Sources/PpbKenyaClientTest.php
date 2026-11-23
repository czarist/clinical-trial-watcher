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
use App\Services\Registries\Sources\PpbKenya\PpbKenyaSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class PpbKenyaClientTest extends TestCase
{
    use RefreshDatabase;

    private const array HTML = ['Content-Type' => 'text/html; charset=UTF-8'];

    private const string LIST = 'ctr.pharmacyboardkenya.org/applications/index/sort:approval_date/direction:desc/status:';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 100, 'max_sleep_ms' => 1000],
        ]);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/registries/ppb_kenya/'.$name));
    }

    /**
     * @param  array<string, mixed>  $responses
     */
    private function fake(array $responses): void
    {
        Http::fake(['ctr.pharmacyboardkenya.org/robots.txt' => Http::response('')] + $responses);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(PpbKenyaSource::class)->client();
    }

    public function test_records_are_read_from_the_lists_by_status_in_turn(): void
    {
        $this->fake([
            self::LIST.'approved/page:1' => Http::response(self::fixture('list_single.html'), 200, self::HTML),
            self::LIST.'suspended/page:1' => Http::response(self::fixture('list_empty.html'), 200, self::HTML),
            'ctr.pharmacyboardkenya.org/applications/view/1660' => Http::response(self::fixture('view_1660.html'), 200, self::HTML),
            'ctr.pharmacyboardkenya.org/applications/view/1700' => Http::response(self::fixture('view_1700.html'), 200, self::HTML),
        ]);

        $first = $this->client()->fetchPage(1);

        $this->assertSame([1660], array_column($first->studies, 'view_id'));
        $this->assertSame('0:1:1', $first->nextPageToken);
        $this->assertSame('approved', $first->studies[0]['list_status']);
        $this->assertSame('https://ctr.pharmacyboardkenya.org/applications/view/1660', $first->studies[0]['url']);
        $this->assertSame('Kericho', $first->studies[0]['sites'][0]['county']);
        $this->assertArrayNotHasKey('investigators', $first->studies[0]);

        $second = $this->client()->fetchPage(100, $first->nextPageToken);
        $this->assertSame([1700], array_column($second->studies, 'view_id'));
        $this->assertSame('1:1:0', $second->nextPageToken);

        $stopped = $this->client()->fetchPage(100, '2:1:0');
        $this->assertSame([], $stopped->studies);
        $this->assertNull($stopped->nextPageToken);
    }

    public function test_the_next_list_page_is_followed_until_the_last(): void
    {
        $this->fake([
            self::LIST.'approved/page:1' => Http::response(self::fixture('list_approved_p1.html'), 200, self::HTML),
            'ctr.pharmacyboardkenya.org/applications/view/*' => Http::response(self::fixture('view_1660.html'), 200, self::HTML),
        ]);

        $page = $this->client()->fetchPage(100);

        $this->assertCount(10, $page->studies);
        $this->assertSame('0:2:0', $page->nextPageToken);
    }

    public function test_an_incremental_run_stops_at_the_first_trial_approved_before_the_cursor(): void
    {
        config(['registries.options.ppb_kenya.statuses' => ['approved']]);
        $this->fake([
            self::LIST.'approved/page:1' => Http::response(self::fixture('list_approved_p1.html'), 200, self::HTML),
            'ctr.pharmacyboardkenya.org/applications/view/*' => Http::response(self::fixture('view_1660.html'), 200, self::HTML),
        ]);

        $page = $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-10-22'));

        // Approved on 08-11-2026 (3) and 23-10-2026 (4); the next one, 21-10-2026, ends the list.
        $this->assertCount(7, $page->studies);
        $this->assertNull($page->nextPageToken);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'page:2'));
    }

    public function test_an_empty_list_moves_to_the_next_status(): void
    {
        $this->fake([self::LIST.'stopped/page:1' => Http::response(self::fixture('list_empty.html'), 200, self::HTML)]);

        $page = $this->client()->fetchPage(100, '1:1:0');

        $this->assertSame([], $page->studies);
        $this->assertSame('2:1:0', $page->nextPageToken);
    }

    public function test_a_changed_list_fails_instead_of_returning_nothing(): void
    {
        $this->fake([self::LIST.'approved/page:1' => Http::response('<html><body><h1>Login</h1></body></html>', 200, self::HTML)]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_record_page_without_a_record_fails(): void
    {
        $this->fake([
            self::LIST.'approved/page:1' => Http::response(self::fixture('list_single.html'), 200, self::HTML),
            'ctr.pharmacyboardkenya.org/applications/view/*' => Http::response('<html><body>Missing</body></html>', 200, self::HTML),
        ]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fake([
            self::LIST.'stopped/page:1' => Http::sequence()
                ->push('', 503)
                ->push(self::fixture('list_empty.html'), 200, self::HTML),
        ]);

        $page = $this->client()->fetchPage(100, '1:1:0');

        $this->assertSame([], $page->studies);
        Http::assertSentCount(3); // robots.txt, the failed attempt and the retry
    }

    public function test_an_identical_second_import_leaves_the_studies_unchanged(): void
    {
        config(['registries.sources' => [
            ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
            PpbKenyaSource::KEY => PpbKenyaSource::class,
        ]]);
        $this->app->forgetInstance(SourceRegistry::class);
        $this->fake([
            self::LIST.'approved/page:1' => Http::response(self::fixture('list_single.html'), 200, self::HTML),
            self::LIST.'stopped/page:1' => Http::response(self::fixture('list_empty.html'), 200, self::HTML),
            self::LIST.'suspended/page:1' => Http::response(self::fixture('list_empty.html'), 200, self::HTML),
            'ctr.pharmacyboardkenya.org/applications/view/1660' => Http::response(self::fixture('view_1660.html'), 200, self::HTML),
            'ctr.pharmacyboardkenya.org/applications/view/1700' => Http::response(self::fixture('view_1700.html'), 200, self::HTML),
        ]);
        $import = fn () => $this->app->make(ClinicalTrialImporter::class)->import(new ImportOptions(full: true, source: PpbKenyaSource::KEY));

        $first = $import();

        $this->assertSame(ImportRunStatus::Succeeded, $first->status);
        $this->assertSame(2, $first->studies_created);
        $trial = ClinicalTrial::query()->where('source', PpbKenyaSource::KEY)->where('source_id', 'ECCT/26/06/01')->sole();
        $this->assertSame('PHASE1', $trial->phase);
        $this->assertSame(['Kenya Medical Research Institute Walter Reed Project', 'KEMRI Butere Clinical Research Centre'], $trial->locations()->pluck('facility')->all());
        $this->assertSame(0, $trial->secondaryIds()->count());
        $this->assertSame('SUSPENDED', ClinicalTrial::query()->where('source_id', 'view-1700')->value('overall_status'));

        $second = $import();

        $this->assertSame(ImportRunStatus::Succeeded, $second->status);
        $this->assertSame(2, $second->studies_unchanged);
        $this->assertSame(0, $second->studies_created + $second->studies_updated);
        $this->assertSame(2, ClinicalTrial::query()->where('source', PpbKenyaSource::KEY)->count());
    }
}
