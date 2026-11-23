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
use App\Services\Registries\Sources\HealthCanada\HealthCanadaSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

final class HealthCanadaClientTest extends TestCase
{
    use RefreshDatabase;

    private const string PROTOCOLS = 'health-products.canada.ca/api/clinical-trial/protocol/*';

    private const string DRUG_PRODUCTS = 'health-products.canada.ca/api/clinical-trial/drugproduct/*';

    private const array JSON = ['Content-Type' => 'application/json; charset=utf-8'];

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
                HealthCanadaSource::KEY => HealthCanadaSource::class,
            ],
        ]);
        $this->app->forgetInstance(SourceRegistry::class);
    }

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/registries/health_canada/'.$name));
    }

    private function client(): StudyRegistryClient
    {
        return $this->app->make(HealthCanadaSource::class)->client();
    }

    private function fakeLists(string $protocols = 'protocol_list.json'): void
    {
        Http::fake([
            self::PROTOCOLS => Http::response(self::fixture($protocols), 200, self::JSON),
            self::DRUG_PRODUCTS => Http::response(self::fixture('drugproduct_list.json'), 200, self::JSON),
        ]);
    }

    public function test_the_lists_are_read_once_and_paged_with_their_drug_products(): void
    {
        $this->fakeLists();
        $client = $this->client();

        $first = $client->fetchPage(2);
        $second = $client->fetchPage(2, $first->nextPageToken);

        $this->assertCount(2, $first->studies);
        $this->assertSame('2', $first->nextPageToken);
        $this->assertSame(37653, $first->studies[1]['protocol']['protocol_id']);
        $this->assertSame('RITUXAN', $first->studies[1]['drugProducts'][0]['brand_name']);
        $this->assertSame('https://health-products.canada.ca/api/clinical-trial/protocol/?id=37653&lang=en&type=json', $first->studies[1]['url']);
        $this->assertCount(1, $second->studies);
        $this->assertSame([], $second->studies[0]['drugProducts']);
        $this->assertNull($second->nextPageToken);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://health-products.canada.ca/api/clinical-trial/protocol/?lang=en&type=json');
    }

    public function test_an_empty_list_is_an_empty_last_page(): void
    {
        $this->fakeLists('empty.json');

        $page = $this->client()->fetchPage(100);

        $this->assertSame([], $page->studies);
        $this->assertNull($page->nextPageToken);
    }

    public function test_a_response_that_is_not_a_protocol_list_fails(): void
    {
        Http::fake([
            self::PROTOCOLS => Http::response('<html><body>Maintenance</body></html>', 200, ['Content-Type' => 'text/html']),
            self::DRUG_PRODUCTS => Http::response(self::fixture('drugproduct_list.json'), 200, self::JSON),
        ]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_changed_json_shape_fails(): void
    {
        Http::fake([
            self::PROTOCOLS => Http::response('{"protocols": []}', 200, self::JSON),
            self::DRUG_PRODUCTS => Http::response(self::fixture('drugproduct_list.json'), 200, self::JSON),
        ]);

        $this->expectException(RegistryRequestFailed::class);
        $this->client()->fetchPage(100);
    }

    public function test_a_server_error_is_retried(): void
    {
        Http::fake([
            self::PROTOCOLS => Http::sequence()->push('', 500)->push(self::fixture('protocol_list.json'), 200, self::JSON),
            self::DRUG_PRODUCTS => Http::response(self::fixture('drugproduct_list.json'), 200, self::JSON),
        ]);

        $page = $this->client()->fetchPage(100);

        $this->assertCount(3, $page->studies);
        Http::assertSentCount(3);
    }

    public function test_an_import_creates_the_studies_and_a_repeated_import_leaves_them_unchanged(): void
    {
        $this->fakeLists();
        $options = new ImportOptions(full: true, source: HealthCanadaSource::KEY);

        $first = $this->app->make(ClinicalTrialImporter::class)->import($options);

        $this->assertSame(ImportRunStatus::Succeeded, $first->status);
        $this->assertSame(3, $first->studies_created);
        $trial = ClinicalTrial::query()->where('source', HealthCanadaSource::KEY)->where('source_id', '37653')->sole();
        $this->assertSame('HOFFMANN-LA ROCHE LIMITED', $trial->sponsor_name);
        $this->assertSame('json', $trial->raw_format);
        $this->assertSame(['MICROSCOPIC POLYANGITIS', 'POLYANGITIS'], $trial->conditions()->orderBy('condition')->pluck('condition')->all());

        $second = $this->app->make(ClinicalTrialImporter::class)->import($options);

        $this->assertSame(ImportRunStatus::Succeeded, $second->status);
        $this->assertSame(0, $second->studies_created);
        $this->assertSame(3, $second->studies_unchanged);
        $this->assertSame(3, ClinicalTrial::query()->where('source', HealthCanadaSource::KEY)->count());
    }
}
