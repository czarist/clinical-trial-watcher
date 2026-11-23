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
use App\Services\Registries\Sources\Rebec\RebecSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class RebecClientTest extends TestCase
{
    use RefreshDatabase;

    private const array HTML = ['Content-Type' => 'text/html; charset=UTF-8'];

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
        return (string) file_get_contents(base_path('tests/Fixtures/registries/rebec/'.$name));
    }

    /**
     * @param  array<string, mixed>  $responses
     */
    private function fake(array $responses): void
    {
        Http::fake(['ensaiosclinicos.gov.br/robots.txt' => Http::response(self::fixture('robots.txt'))] + $responses);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(RebecSource::class)->client();
    }

    public function test_listed_trials_are_returned_with_their_pages_and_the_token_moves_through_the_listing(): void
    {
        $this->fake([
            'ensaiosclinicos.gov.br/list/1' => Http::response(self::fixture('list_1.html'), 200, self::HTML),
            'ensaiosclinicos.gov.br/rg/*' => Http::response(self::fixture('trial_RBR-10jdwz27.html'), 200, self::HTML),
        ]);

        $first = $this->client()->fetchPage(5);

        $this->assertCount(5, $first->studies);
        $this->assertSame('1:5', $first->nextPageToken);
        $this->assertSame('RBR-34bs2hf', $first->studies[0]['id']);
        $this->assertSame('https://ensaiosclinicos.gov.br/rg/RBR-34bs2hf', $first->studies[0]['url']);
        $this->assertSame(1, $first->studies[0]['revision']);
        $this->assertStringNotContainsString('class="vcard"', $first->studies[0]['html']);

        $last = $this->client()->fetchPage(10, '1:15');

        $this->assertCount(5, $last->studies);
        $this->assertSame('2:0', $last->nextPageToken);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Accept-Language', 'en'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/xml_ictrp/'));
    }

    public function test_an_incremental_run_fetches_only_trials_approved_since_the_cursor(): void
    {
        $this->fake([
            'ensaiosclinicos.gov.br/list/1' => Http::response(self::fixture('list_single.html'), 200, self::HTML),
            'ensaiosclinicos.gov.br/rg/RBR-10jdwz27' => Http::response(self::fixture('trial_RBR-10jdwz27.html'), 200, self::HTML),
        ]);

        $page = $this->client()->fetchPage(100, null, CarbonImmutable::parse('2026-11-01'));

        $this->assertSame(['RBR-10jdwz27'], array_column($page->studies, 'id'));
        $this->assertNull($page->nextPageToken);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'RBR-7b6xk3m'));
    }

    public function test_an_empty_listing_ends_the_run(): void
    {
        $this->fake(['ensaiosclinicos.gov.br/list/7' => Http::response(self::fixture('list_empty.html'), 200, self::HTML)]);

        $page = $this->client()->fetchPage(100, '7:0');

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_changed_listing_fails_instead_of_returning_nothing(): void
    {
        $this->fake(['ensaiosclinicos.gov.br/list/1' => Http::response('<html><body><h1>Nova versão do site</h1></body></html>', 200, self::HTML)]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_trial_page_without_a_record_fails(): void
    {
        $this->fake([
            'ensaiosclinicos.gov.br/list/1' => Http::response(self::fixture('list_single.html'), 200, self::HTML),
            'ensaiosclinicos.gov.br/rg/*' => Http::response('<html><body>Not found</body></html>', 200, self::HTML),
        ]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fake([
            'ensaiosclinicos.gov.br/list/1' => Http::sequence()
                ->push('', 500)
                ->push(self::fixture('list_empty.html'), 200, self::HTML),
        ]);

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        Http::assertSentCount(3); // robots.txt, the failed attempt and the retry
    }

    public function test_an_identical_second_import_leaves_the_studies_unchanged(): void
    {
        config(['registries.sources' => [
            ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
            RebecSource::KEY => RebecSource::class,
        ]]);
        $this->app->forgetInstance(SourceRegistry::class);
        $this->fake([
            'ensaiosclinicos.gov.br/list/1' => Http::response(self::fixture('list_single.html'), 200, self::HTML),
            'ensaiosclinicos.gov.br/rg/RBR-10jdwz27' => Http::response(self::fixture('trial_RBR-10jdwz27.html'), 200, self::HTML),
            'ensaiosclinicos.gov.br/rg/RBR-7b6xk3m' => Http::response(self::fixture('trial_RBR-7b6xk3m.html'), 200, self::HTML),
        ]);
        $import = fn () => $this->app->make(ClinicalTrialImporter::class)->import(new ImportOptions(full: true, source: RebecSource::KEY));

        $first = $import();

        $this->assertSame(ImportRunStatus::Succeeded, $first->status);
        $this->assertSame(2, $first->studies_created);
        $trial = ClinicalTrial::query()->where('source', RebecSource::KEY)->where('source_id', 'RBR-10jdwz27')->sole();
        $this->assertSame('RECRUITING', $trial->overall_status);
        $this->assertSame('html', $trial->raw_format);
        $this->assertSame(['U1111-1349-7287', '97887926510015062', '8.490.993'], $trial->secondaryIds()->pluck('secondary_id')->all());
        $this->assertStringNotContainsString('class="vcard"', (string) $trial->raw_document);

        $second = $import();

        $this->assertSame(ImportRunStatus::Succeeded, $second->status);
        $this->assertSame(2, $second->studies_unchanged);
        $this->assertSame(0, $second->studies_created + $second->studies_updated);
        $this->assertSame(2, ClinicalTrial::query()->where('source', RebecSource::KEY)->count());
    }
}
