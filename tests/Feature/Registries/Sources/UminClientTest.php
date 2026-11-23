<?php

declare(strict_types=1);

namespace Tests\Feature\Registries\Sources;

use App\Enums\ImportRunStatus;
use App\Exceptions\RegistryAccessRefused;
use App\Exceptions\RegistryRequestFailed;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Import\ClinicalTrialImporter;
use App\Services\ClinicalTrials\Import\ImportOptions;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\ClinicalTrialsGovSource;
use App\Services\Registries\SourceRegistry;
use App\Services\Registries\Sources\Umin\UminClient;
use App\Services\Registries\Sources\Umin\UminSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class UminClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/umin/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 10, 'max_sleep_ms' => 100],
            'registries.permissions.umin' => true,
            'registries.sources' => [
                ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
                UminSource::KEY => UminSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(SourceRegistry::class)->get(UminSource::KEY)->client();
    }

    private static function dump(string $name = 'synthetic_ctr_data_e.csv.gz'): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }

    /**
     * @param  mixed  $dump  a response or sequence for the dump URL
     */
    private function fakeUmin(mixed $dump = null): void
    {
        Http::fake([
            'upload.umin.ac.jp/robots.txt' => Http::response('', 404),
            UminClient::DUMP_URL => $dump ?? Http::response(self::dump(), 200, ['Content-Type' => 'application/x-gzip']),
        ]);
    }

    private function dumpRequests(): int
    {
        return Http::recorded(fn (Request $request): bool => $request->url() === UminClient::DUMP_URL)->count();
    }

    public function test_rows_are_paged_from_one_download(): void
    {
        $this->fakeUmin();
        $client = $this->client();

        $first = $client->fetchPage(2);
        $this->assertSame(['UMIN000012345', 'UMIN000067890'], array_column($first->studies, 'Unique ID issued by UMIN'));
        $this->assertNotNull($first->nextPageToken);
        $this->assertStringStartsWith('2:', (string) $first->nextPageToken);

        $second = $client->fetchPage(2, $first->nextPageToken);
        $this->assertSame(['UMIN000099999'], array_column($second->studies, 'Unique ID issued by UMIN'));
        $this->assertNull($second->nextPageToken);

        $this->assertSame(1, $this->dumpRequests());
    }

    public function test_an_empty_dump_returns_no_studies(): void
    {
        $this->fakeUmin(Http::response(self::dump('synthetic_ctr_data_e_empty.csv.gz'), 200, ['Content-Type' => 'application/x-gzip']));

        $page = $this->client()->fetchPage(50);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_an_incremental_run_keeps_rows_modified_since_the_date(): void
    {
        $this->fakeUmin();

        $page = $this->client()->fetchPage(50, null, CarbonImmutable::parse('2026-01-01'));

        $this->assertSame(['UMIN000012345'], array_column($page->studies, 'Unique ID issued by UMIN'));
    }

    public function test_a_page_that_is_not_the_gzip_dump_fails_the_request(): void
    {
        $this->fakeUmin(Http::response('<html><body>Maintenance</body></html>', 200, ['Content-Type' => 'text/html']));

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(10);
    }

    public function test_a_dump_with_a_changed_layout_fails_the_request(): void
    {
        $this->fakeUmin(Http::response((string) gzencode("id,title\n1,Changed\n"), 200, ['Content-Type' => 'application/x-gzip']));

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(10);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fakeUmin(Http::sequence()
            ->push('Service unavailable', 503)
            ->push(self::dump(), 200, ['Content-Type' => 'application/x-gzip']));

        $page = $this->client()->fetchPage(10);

        $this->assertCount(3, $page->studies);
        $this->assertSame(2, $this->dumpRequests());
    }

    public function test_without_written_permission_no_request_is_made(): void
    {
        config(['registries.permissions.umin' => false]);
        Http::fake();

        try {
            $this->client()->fetchPage(10);
            $this->fail('UMIN-CTR must not be read without written permission.');
        } catch (RegistryAccessRefused) {
            // Expected.
        }

        $run = $this->app->make(ClinicalTrialImporter::class)->import(new ImportOptions(full: true, source: UminSource::KEY));

        $this->assertSame(ImportRunStatus::Failed, $run->status);
        $this->assertStringContainsString('not allowed', (string) $run->error);
        $this->assertSame(0, ClinicalTrial::query()->where('source', UminSource::KEY)->count());
        Http::assertNothingSent();
    }

    public function test_an_import_creates_the_studies_and_an_identical_import_leaves_them_unchanged(): void
    {
        $this->fakeUmin();
        $importer = $this->app->make(ClinicalTrialImporter::class);
        $options = new ImportOptions(pageSize: 2, full: true, source: UminSource::KEY);

        $first = $importer->import($options);
        $this->assertSame(3, $first->studies_created, (string) $first->error);
        $this->assertSame(3, ClinicalTrial::query()->where('source', UminSource::KEY)->count());

        $trial = ClinicalTrial::query()->where('source', UminSource::KEY)->where('source_id', 'UMIN000012345')->sole();
        $this->assertSame('RECRUITING', $trial->overall_status);
        $this->assertSame('csv', $trial->raw_format);
        $this->assertStringNotContainsString('Redacted', (string) $trial->raw_document);
        $this->assertEqualsCanonicalizing(['NCT05123456', 'jRCTs031230001'], $trial->secondaryIds()->pluck('secondary_id')->all());

        $second = $importer->import($options);
        $this->assertSame(3, $second->studies_unchanged, (string) $second->error);
        $this->assertSame(0, $second->studies_created);
        $this->assertSame(3, ClinicalTrial::query()->where('source', UminSource::KEY)->count());
    }
}
