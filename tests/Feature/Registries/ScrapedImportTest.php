<?php

declare(strict_types=1);

namespace Tests\Feature\Registries;

use App\Enums\AccessMethod;
use App\Enums\ImportRunStatus;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ImportRun;
use App\Services\ClinicalTrials\Import\ClinicalTrialImporter;
use App\Services\ClinicalTrials\Import\ImportOptions;
use App\Services\Registries\ClinicalTrialsGovSource;
use App\Services\Registries\SourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeRegistrySource;
use Tests\TestCase;

final class ScrapedImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['registries.sources' => [
            ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
            FakeRegistrySource::KEY => FakeRegistrySource::class,
        ]]);
        $this->app->forgetInstance(SourceRegistry::class);
        FakeRegistrySource::reset();
        FakeRegistrySource::$method = AccessMethod::Scraping;
    }

    private function import(bool $full = true): ImportRun
    {
        return $this->app->make(ClinicalTrialImporter::class)->import(new ImportOptions(full: $full, source: FakeRegistrySource::KEY));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function payloads(int $count, bool $complete = true): array
    {
        return array_map(fn (int $i): array => [
            'id' => sprintf('ISRCTN%08d', $i),
            'title' => "Study {$i}",
            'status' => $complete ? 'Recruiting' : null,
            'phase' => $complete ? 'Phase 2' : null,
            'sponsor' => $complete ? 'Sponsor' : null,
            'raw' => "<html>record {$i}</html>",
        ], range(1, $count));
    }

    public function test_the_raw_page_and_content_hash_are_kept_and_an_identical_record_is_not_rewritten(): void
    {
        FakeRegistrySource::$payloads = self::payloads(1);
        $this->import();
        $trial = ClinicalTrial::query()->sole();

        $this->assertSame('html', $trial->raw_format);
        $this->assertSame('<html>record 1</html>', $trial->raw_document);
        $this->assertSame('https://registry.test/ISRCTN00000001', $trial->raw_url);
        $this->assertNotNull($trial->content_hash);

        // The raw page changes but nothing mapped does: unchanged, only the raw copy is refreshed.
        FakeRegistrySource::$payloads[0]['raw'] = '<html>record 1, new banner</html>';
        $run = $this->import();

        $this->assertSame(1, $run->studies_unchanged);
        $this->assertSame('<html>record 1, new banner</html>', $trial->fresh()->raw_document);
        $this->assertSame(1, $trial->trialChanges()->count());

        FakeRegistrySource::$payloads[0]['status'] = 'Completed';
        $this->assertSame(1, $this->import()->studies_updated);
        $this->assertNotSame($trial->content_hash, $trial->fresh()->content_hash);
    }

    public function test_a_full_run_with_far_fewer_records_is_partial_and_keeps_existing_studies(): void
    {
        FakeRegistrySource::$payloads = self::payloads(30);
        $first = $this->import();
        $this->assertSame(ImportRunStatus::Succeeded, $first->status);

        FakeRegistrySource::$payloads = self::payloads(5);
        $second = $this->import();

        $this->assertSame(ImportRunStatus::Partial, $second->status);
        $this->assertStringContainsString('5 records parsed, against 30', (string) $second->error);
        $this->assertSame(30, ClinicalTrial::query()->count());
    }

    public function test_a_sharp_drop_in_filled_fields_is_partial(): void
    {
        FakeRegistrySource::$payloads = self::payloads(3);
        $this->import(full: false);

        FakeRegistrySource::$payloads = self::payloads(3, complete: false);
        $run = $this->import(full: false);

        $this->assertSame(ImportRunStatus::Partial, $run->status);
        $this->assertStringContainsString('of mapped fields filled', (string) $run->error);
        $this->assertLessThan(0.5, (float) $run->field_fill_ratio);
    }

    public function test_api_sources_are_not_checked_for_layout_drift(): void
    {
        FakeRegistrySource::$method = AccessMethod::Api;
        FakeRegistrySource::$payloads = self::payloads(30);
        $this->import();

        FakeRegistrySource::$payloads = self::payloads(5);

        $this->assertSame(ImportRunStatus::Succeeded, $this->import()->status);
    }

    public function test_a_refused_source_fails_the_run(): void
    {
        FakeRegistrySource::$allowed = false;

        $this->artisan('clinicaltrials:fetch', ['--source' => FakeRegistrySource::KEY])->assertFailed();

        $run = ImportRun::query()->sole();
        $this->assertSame(ImportRunStatus::Failed, $run->status);
        $this->assertStringContainsString('permission', (string) $run->error);
    }
}
