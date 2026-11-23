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
use App\Services\Registries\Sources\Sukl\SuklSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class SuklClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/sukl/';

    private const string BASE = 'https://prehledy.sukl.cz/pkh/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 10, 'max_sleep_ms' => 100],
            'registries.permissions.sukl' => true,
            'registries.sources' => [
                ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
                SuklSource::KEY => SuklSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(SourceRegistry::class)->get(SuklSource::KEY)->client();
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }

    private static function detailFixture(string $eudract): string
    {
        return is_file(self::FIXTURES."detail_{$eudract}.json") ? "detail_{$eudract}.json" : "synthetic_detail_{$eudract}.json";
    }

    /**
     * Fakes the database: robots.txt is absent, the search serves two pages (or the given
     * listing for every page) and each detail serves its fixture. The first `$failures`
     * search requests answer HTTP 503.
     */
    private function fakeDatabase(?string $listing = null, int $failures = 0): void
    {
        Http::fake(function (Request $request) use ($listing, &$failures) {
            $url = $request->url();
            $json = ['Content-Type' => 'application/json'];

            if ($url === 'https://prehledy.sukl.cz/robots.txt') {
                return Http::response('', 404);
            }

            if ($url === self::BASE.'vyhledej') {
                if ($failures > 0) {
                    $failures--;

                    return Http::response('Service unavailable', 503);
                }

                return Http::response(self::fixture($listing ?? ($request->data()['stranka'] === 1 ? 'vyhledej_page1.json' : 'vyhledej_page2.json')), 200, $json);
            }

            if (preg_match('#^'.preg_quote(self::BASE, '#').'detail/([0-9-]+)/jazyk/en$#', $url, $match) === 1) {
                return Http::response(self::fixture(self::detailFixture($match[1])), 200, $json);
            }

            return Http::response('', 404);
        });
    }

    public function test_trials_are_listed_in_pages_and_read_in_full(): void
    {
        $this->fakeDatabase();

        $first = $this->client()->fetchPage(2);
        $this->assertSame(['2014-000555-93', '2004-000711-26'], array_column($first->studies, 'eudract'));
        $this->assertSame('2|2', $first->nextPageToken);

        $second = $this->client()->fetchPage(2, $first->nextPageToken);
        $this->assertSame(['2014-004438-24'], array_column($second->studies, 'eudract'));
        $this->assertSame('AbbVie Deutschland, GmbH & Co. KG, Knollstrasse, 67061 Ludwigshafen, Germany', $second->studies[0]['zadavatel']);
        $this->assertNull($second->nextPageToken);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::BASE.'vyhledej'
            && $request->method() === 'POST'
            && $request->data() === ['filtr' => '', 'stav' => 'all', 'jazyk' => 'en', 'pocet' => 2, 'stranka' => 1, 'sort' => ['nazev'], 'smer' => 'asc']
            && str_contains($request->header('User-Agent')[0], 'https://watcher.example/contact'));
    }

    public function test_an_empty_search_returns_no_trials(): void
    {
        $this->fakeDatabase('vyhledej_empty.json');

        $page = $this->client()->fetchPage(10);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_changed_search_response_fails_the_request(): void
    {
        Http::fake([
            'prehledy.sukl.cz/robots.txt' => Http::response('', 404),
            self::BASE.'vyhledej' => Http::response('<html><body>Údržba</body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(10);
    }

    public function test_a_detail_that_is_not_the_trial_fails_the_request(): void
    {
        Http::fake([
            'prehledy.sukl.cz/robots.txt' => Http::response('', 404),
            self::BASE.'vyhledej' => Http::response(self::fixture('vyhledej_page1.json'), 200, ['Content-Type' => 'application/json']),
            self::BASE.'detail/*' => Http::response('{"eudract":"2099-000000-00"}', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(10);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fakeDatabase(failures: 1);

        $page = $this->client()->fetchPage(2);

        $this->assertCount(2, $page->studies);
        $this->assertSame(2, Http::recorded(fn (Request $request): bool => $request->url() === self::BASE.'vyhledej')->count());
    }

    public function test_without_written_permission_no_request_is_made(): void
    {
        config(['registries.permissions.sukl' => false]);
        $this->app->forgetInstance(SourceRegistry::class);
        Http::fake();

        $run = $this->app->make(ClinicalTrialImporter::class)->import(new ImportOptions(full: true, source: SuklSource::KEY));

        $this->assertSame(ImportRunStatus::Failed, $run->status);
        $this->assertStringContainsString('not allowed', (string) $run->error);
        Http::assertNothingSent();
        $this->assertSame(0, ClinicalTrial::query()->where('source', SuklSource::KEY)->count());
    }

    public function test_an_import_creates_the_trials_and_an_identical_import_leaves_them_unchanged(): void
    {
        $this->fakeDatabase();
        $importer = $this->app->make(ClinicalTrialImporter::class);
        $options = new ImportOptions(pageSize: 2, full: true, source: SuklSource::KEY);

        $first = $importer->import($options);
        $this->assertSame(3, $first->studies_created, (string) $first->error);

        $trial = ClinicalTrial::query()->where('source', SuklSource::KEY)->where('source_id', '2014-004438-24')->sole();
        $this->assertNull($trial->overall_status);
        $this->assertSame(['2014-004438-24'], $trial->secondaryIds()->pluck('secondary_id')->all());
        $this->assertSame(4, $trial->locations()->count());
        $this->assertStringNotContainsString('Redacted', (string) $trial->raw_document);

        $second = $importer->import($options);
        $this->assertSame(3, $second->studies_unchanged, (string) $second->error);
        $this->assertSame(3, ClinicalTrial::query()->where('source', SuklSource::KEY)->count());
    }
}
