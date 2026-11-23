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
use App\Services\Registries\Sources\NciCts\NciCtsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class NciCtsClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/nci_cts/';

    private const string TRIALS = 'https://clinicaltrialsapi.cancer.gov/api/v2/trials';

    private const string KEY = 'test-key-not-real';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 10, 'max_sleep_ms' => 100],
            'registries.options.nci_cts.api_key' => self::KEY,
            'registries.sources' => [
                ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
                NciCtsSource::KEY => NciCtsSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(SourceRegistry::class)->get(NciCtsSource::KEY)->client();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }

    /** Fakes the trials search: the page is chosen by the body's offset. */
    private function fakeApi(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() !== 'POST' || $request->url() !== self::TRIALS) {
                return Http::response('', 404);
            }

            $fixture = match ($request->data()['from'] ?? null) {
                0 => 'synthetic_trials_page1.json',
                2 => 'synthetic_trials_page2.json',
                default => 'synthetic_trials_empty.json',
            };

            return Http::response(self::fixture($fixture), 200, ['Content-Type' => 'application/json; charset=utf-8']);
        });
    }

    public function test_pages_are_followed_by_offset_with_the_key_in_a_header(): void
    {
        $this->fakeApi();

        $first = $this->client()->fetchPage(2);
        $this->assertSame(['NCI-2026-00123', 'NCI-2025-04567'], array_column($first->studies, 'nci_id'));
        $this->assertSame('2', $first->nextPageToken);

        $second = $this->client()->fetchPage(2, $first->nextPageToken);
        $this->assertSame(['NCI-2018-09876'], array_column($second->studies, 'nci_id'));
        $this->assertNull($second->nextPageToken);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::TRIALS
            && $request->hasHeader('X-API-KEY', self::KEY)
            && $request->data()['from'] === 0 && $request->data()['size'] === 2);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), self::KEY));
    }

    public function test_the_page_size_is_capped(): void
    {
        $this->fakeApi();

        $this->client()->fetchPage(500);

        Http::assertSent(fn (Request $request): bool => $request->data()['size'] === 50);
    }

    public function test_an_empty_result_returns_no_studies(): void
    {
        Http::fake([self::TRIALS => Http::response(self::fixture('synthetic_trials_empty.json'), 200, ['Content-Type' => 'application/json'])]);

        $page = $this->client()->fetchPage(50);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_missing_key_fails_before_any_request(): void
    {
        config(['registries.options.nci_cts.api_key' => '']);
        Http::fake();

        try {
            $this->client()->fetchPage(10);
            $this->fail('A run without a key must fail.');
        } catch (RegistryRequestFailed $exception) {
            $this->assertStringContainsString('REGISTRY_NCI_CTS_API_KEY', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_html_page_served_with_http_200_fails_the_request(): void
    {
        Http::fake([self::TRIALS => Http::response(self::fixture('unauthenticated.html'), 200, ['Content-Type' => 'text/html'])]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(10);
    }

    public function test_a_changed_response_format_fails_the_request(): void
    {
        Http::fake([self::TRIALS => Http::response('{"trials":[]}', 200, ['Content-Type' => 'application/json'])]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(10);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $calls = 0;
        Http::fake(function () use (&$calls) {
            return ++$calls === 1
                ? Http::response('Internal error', 500)
                : Http::response(self::fixture('synthetic_trials_page2.json'), 200, ['Content-Type' => 'application/json']);
        });

        $page = $this->client()->fetchPage(10);

        $this->assertSame(2, $calls);
        $this->assertSame(['NCI-2018-09876'], array_column($page->studies, 'nci_id'));
    }

    public function test_an_import_creates_the_trials_and_an_identical_import_leaves_them_unchanged(): void
    {
        $this->fakeApi();
        $importer = $this->app->make(ClinicalTrialImporter::class);
        $options = new ImportOptions(pageSize: 2, full: true, source: NciCtsSource::KEY);

        $first = $importer->import($options);
        $this->assertSame(3, $first->studies_created, (string) $first->error);
        $this->assertSame(3, ClinicalTrial::query()->where('source', NciCtsSource::KEY)->count());

        $trial = ClinicalTrial::query()->where('source', NciCtsSource::KEY)->where('source_id', 'NCI-2026-00123')->sole();
        $this->assertSame('RECRUITING', $trial->overall_status);
        $this->assertSame('json', $trial->raw_format);
        $this->assertStringNotContainsString('contact_email', (string) $trial->raw_document);
        $this->assertSame(['NCT07012345'], $trial->secondaryIds()->pluck('secondary_id')->all());

        $second = $importer->import($options);
        $this->assertSame(3, $second->studies_unchanged, (string) $second->error);
        $this->assertSame(0, $second->studies_created);
        $this->assertSame(3, ClinicalTrial::query()->where('source', NciCtsSource::KEY)->count());
    }
}
