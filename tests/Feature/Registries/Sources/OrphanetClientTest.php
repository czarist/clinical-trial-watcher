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
use App\Services\Registries\Sources\Orphanet\OrphanetSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class OrphanetClientTest extends TestCase
{
    use RefreshDatabase;

    private const string FIXTURES = __DIR__.'/../../../Fixtures/registries/orphanet/';

    /** A delivery URL as the contract would provide it (invented host). */
    private const string DUMP = 'https://delivery.orphadata.example/contract/National_ClinicalTrials.xml';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Sleep::fake();
        Http::preventStrayRequests();
        config([
            'registries.fetching.contact' => 'https://watcher.example/contact',
            'registries.fetching.retry' => ['times' => 3, 'sleep_ms' => 10, 'max_sleep_ms' => 100],
            'registries.permissions.orphanet' => true,
            'registries.options.orphanet.dump_url' => self::DUMP,
            'registries.sources' => [
                ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
                OrphanetSource::KEY => OrphanetSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(SourceRegistry::class)->get(OrphanetSource::KEY)->client();
    }

    private static function fixture(string $name = 'synthetic_trials.xml'): string
    {
        return (string) file_get_contents(self::FIXTURES.$name);
    }

    /**
     * @param  mixed  $dump  a response or sequence for the dump URL
     */
    private function fakeDelivery(mixed $dump): void
    {
        Http::fake([
            'delivery.orphadata.example/robots.txt' => Http::response('', 404),
            self::DUMP => $dump,
        ]);
    }

    private function dumpRequests(): int
    {
        return Http::recorded(fn (Request $request): bool => $request->url() === self::DUMP)->count();
    }

    public function test_trials_are_returned_page_by_page_from_one_download(): void
    {
        $this->fakeDelivery(Http::response(self::fixture(), 200, ['Content-Type' => 'application/xml']));
        $client = $this->client();

        $first = $client->fetchPage(2);
        $this->assertSame(['900001', '900002'], array_column($first->studies, 'id'));
        $this->assertTrue($first->hasNextPage());

        $second = $client->fetchPage(2, $first->nextPageToken);
        $this->assertSame(['900003'], array_column($second->studies, 'id'));
        $this->assertStringStartsWith('<ClinicalTrial id="900003">', $second->studies[0]['xml']);
        $this->assertFalse($second->hasNextPage());

        $this->assertSame(1, $this->dumpRequests());
        Http::assertSent(fn (Request $request): bool => $request->url() === self::DUMP
            && str_contains($request->header('User-Agent')[0], 'https://watcher.example/contact'));
    }

    public function test_an_empty_dump_returns_no_trials(): void
    {
        $this->fakeDelivery(Http::response(self::fixture('synthetic_empty.xml'), 200, ['Content-Type' => 'application/xml']));

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_without_a_delivery_url_the_contract_is_explained_before_any_request(): void
    {
        config(['registries.options.orphanet.dump_url' => '']);
        $this->app->forgetInstance(SourceRegistry::class);
        Http::fake();

        try {
            $this->client()->fetchPage(100);
            $this->fail('A missing delivery URL must fail the request.');
        } catch (RegistryRequestFailed $exception) {
            $this->assertStringContainsString('REGISTRY_ORPHANET_DUMP_URL', $exception->getMessage());
            $this->assertStringContainsString('Orphanet-AISBL', $exception->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_a_response_that_is_not_the_dump_is_rejected(): void
    {
        $this->fakeDelivery(Http::response('{"error":"expired link"}', 200, ['Content-Type' => 'application/json']));

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_dump_with_a_changed_layout_is_rejected(): void
    {
        $this->fakeDelivery(Http::response('<?xml version="1.0"?><JDBOR><Trials/></JDBOR>', 200, ['Content-Type' => 'application/xml']));

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_an_upstream_error_is_retried(): void
    {
        $this->fakeDelivery(Http::sequence()
            ->push('', 500)
            ->push(self::fixture(), 200, ['Content-Type' => 'application/xml']));

        $page = $this->client()->fetchPage(100);

        $this->assertCount(3, $page->studies);
        $this->assertSame(2, $this->dumpRequests());
    }

    public function test_without_a_contract_no_request_is_made(): void
    {
        config(['registries.permissions.orphanet' => false]);
        $this->app->forgetInstance(SourceRegistry::class);
        Http::fake();

        $run = $this->app->make(ClinicalTrialImporter::class)->import(new ImportOptions(full: true, source: OrphanetSource::KEY));

        $this->assertSame(ImportRunStatus::Failed, $run->status);
        $this->assertStringContainsString('not allowed', (string) $run->error);
        Http::assertNothingSent();
        $this->assertSame(0, ClinicalTrial::query()->where('source', OrphanetSource::KEY)->count());
    }

    public function test_an_import_creates_the_trials_and_a_repeated_import_leaves_them_unchanged(): void
    {
        $this->fakeDelivery(Http::response(self::fixture(), 200, ['Content-Type' => 'application/xml', 'ETag' => '"20261101"']));
        $importer = $this->app->make(ClinicalTrialImporter::class);
        $options = new ImportOptions(pageSize: 2, full: true, source: OrphanetSource::KEY);

        $first = $importer->import($options);
        $this->assertSame(3, $first->studies_created, (string) $first->error);
        $this->assertSame(0, $first->studies_failed);

        $trial = ClinicalTrial::query()->where('source', OrphanetSource::KEY)->where('source_id', '900001')->sole();
        $this->assertSame('RECRUITING', $trial->overall_status);
        $this->assertSame(['NCT09000001', '2024-590001-11-00', '2019-000123-45'], $trial->secondaryIds()->orderBy('id')->pluck('secondary_id')->all());
        $this->assertSame(['ORPHA:99001', 'ORPHA:99002'], $trial->keywords()->orderBy('id')->pluck('keyword')->all());
        $this->assertSame('xml', $trial->raw_format);
        $this->assertStringNotContainsString('Redacted', (string) $trial->raw_document);

        $second = $importer->import($options);
        $this->assertSame(0, $second->studies_created);
        $this->assertSame(0, $second->studies_updated);
        $this->assertSame(3, $second->studies_unchanged, (string) $second->error);
        $this->assertSame(3, ClinicalTrial::query()->where('source', OrphanetSource::KEY)->count());
    }
}
