<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\ImportRunStatus;
use App\Enums\TrialChangeType;
use App\Jobs\ImportClinicalTrialsJob;
use App\Jobs\MatchAlertsJob;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialChange;
use App\Models\ClinicalTrials\ClinicalTrialLocation;
use App\Models\ImportRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ImportClinicalTrialsTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = 'https://clinicaltrials.gov/api/v2/studies*';

    protected function setUp(): void
    {
        parent::setUp();

        config(['clinicaltrials.retry.sleep_ms' => 1]);
    }

    public function test_first_import_follows_every_page_and_persists_studies_with_relations(): void
    {
        $fixture = $this->studiesFixture();
        [$first, $second] = $fixture['studies'];

        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($this->page([$first], 'token-2'))
            ->push($this->page([$second]))]);

        $this->artisan('clinicaltrials:fetch', ['--pageSize' => 1])->assertSuccessful();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://clinicaltrials.gov/api/v2/studies')
            && ($request->data()['pageToken'] ?? null) === 'token-2');

        $nctId = $first['protocolSection']['identificationModule']['nctId'];
        $trial = ClinicalTrial::where('nct_id', $nctId)->firstOrFail();

        $this->assertSame(2, ClinicalTrial::count());
        $this->assertSame('RECRUITING', $trial->overall_status);
        $this->assertSame(
            $first['protocolSection']['statusModule']['primaryCompletionDateStruct']['date'],
            $trial->primary_completion_date?->toDateString(),
        );
        $this->assertFalse($trial->has_results);
        $this->assertSame($first, $trial->raw_json);
        $this->assertCount(count($first['protocolSection']['contactsLocationsModule']['locations']), $trial->locations);
        $this->assertSame($first['protocolSection']['conditionsModule']['conditions'], $trial->conditions->pluck('condition')->all());
        $this->assertTrue($trial->meshTerms->isNotEmpty());

        $run = ImportRun::sole();
        $this->assertSame(ImportRunStatus::Succeeded, $run->status);
        $this->assertSame([2, 2, 0], [$run->pages, $run->studies_created, $run->studies_failed]);
        $this->assertSame(2, ClinicalTrialChange::where('type', TrialChangeType::Created)->count());
    }

    public function test_importing_the_same_studies_again_creates_no_duplicates(): void
    {
        $fixture = $this->studiesFixture();
        Http::fake([self::ENDPOINT => Http::response($this->page($fixture['studies']))]);

        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();
        $counts = $this->relationCounts();
        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();

        $this->assertSame(2, ClinicalTrial::count());
        $this->assertSame($counts, $this->relationCounts());
        $this->assertSame(2, ImportRun::latest('id')->first()->studies_unchanged);
        $this->assertSame(2, ClinicalTrialChange::count());
    }

    public function test_a_changed_status_is_recorded_and_an_empty_list_removes_old_rows(): void
    {
        $study = $this->studiesFixture()['studies'][0];
        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($this->page([$study]))
            ->push($this->page([$this->changed($study)]))]);

        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();
        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();

        $trial = ClinicalTrial::sole();
        $this->assertSame('COMPLETED', $trial->overall_status);
        $this->assertSame(0, $trial->locations()->count());
        $this->assertSame(0, $trial->conditions()->count());

        $change = $trial->trialChanges()->latest('id')->first();
        $this->assertSame(TrialChangeType::StatusChanged, $change->type);
        $this->assertSame(['RECRUITING', 'COMPLETED'], [$change->previous_status, $change->new_status]);
        $this->assertSame(1, ImportRun::latest('id')->first()->studies_updated);
    }

    public function test_missing_optional_modules_and_partial_dates_are_handled(): void
    {
        Http::fake([self::ENDPOINT => Http::response($this->page([[
            'protocolSection' => [
                'identificationModule' => ['nctId' => 'NCT09999999', 'briefTitle' => 'Minimal study'],
                'statusModule' => ['startDateStruct' => ['date' => '2024'], 'completionDateStruct' => ['date' => '2026-06']],
            ],
        ]]))]);

        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();

        $trial = ClinicalTrial::sole();
        $this->assertSame('2024-01-01', $trial->start_date->toDateString());
        $this->assertSame('2026-06-01', $trial->completion_date->toDateString());
        $this->assertNull($trial->primary_completion_date);
        $this->assertFalse($trial->has_results);
    }

    public function test_a_failing_study_is_rolled_back_without_stopping_the_run(): void
    {
        [$first, $second] = $this->studiesFixture()['studies'];
        $failingNct = $first['protocolSection']['identificationModule']['nctId'];
        Http::fake([self::ENDPOINT => Http::response($this->page([$first, $second]))]);

        ClinicalTrialLocation::creating(function (ClinicalTrialLocation $location) use ($failingNct): void {
            if (ClinicalTrial::find($location->clinical_trial_id)?->nct_id === $failingNct) {
                throw new QueryException('mysql', 'insert', [], new \RuntimeException('Simulated failure'));
            }
        });

        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();

        $this->assertDatabaseMissing('clinical_trials', ['nct_id' => $failingNct]);
        $this->assertDatabaseHas('clinical_trials', ['nct_id' => $second['protocolSection']['identificationModule']['nctId']]);
        $run = ImportRun::sole();
        $this->assertSame(ImportRunStatus::Partial, $run->status);
        $this->assertSame([1, 1], [$run->studies_created, $run->studies_failed]);
    }

    public function test_a_study_without_nct_id_is_counted_as_failed(): void
    {
        $study = $this->studiesFixture()['studies'][0];
        Http::fake([self::ENDPOINT => Http::response($this->page([['protocolSection' => ['identificationModule' => []]], $study]))]);

        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();

        $this->assertSame(1, ClinicalTrial::count());
        $this->assertSame(1, ImportRun::sole()->studies_failed);
    }

    public function test_server_errors_are_retried_a_bounded_number_of_times_and_fail_the_run(): void
    {
        config(['clinicaltrials.retry.times' => 3]);
        Http::fake([self::ENDPOINT => Http::response(['message' => 'unavailable'], 503)]);

        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertFailed();

        Http::assertSentCount(3);
        $run = ImportRun::sole();
        $this->assertSame(ImportRunStatus::Failed, $run->status);
        $this->assertStringContainsString('503', (string) $run->error);
    }

    public function test_client_errors_are_not_retried(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['message' => 'bad request'], 400)]);

        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertFailed();

        Http::assertSentCount(1);
    }

    public function test_an_incremental_run_requests_studies_updated_since_the_last_successful_run(): void
    {
        ImportRun::create(['status' => ImportRunStatus::Succeeded, 'started_at' => '2026-11-20 03:00:00', 'finished_at' => '2026-11-20 04:00:00']);
        Http::fake([self::ENDPOINT => Http::response($this->page([]))]);

        $this->artisan('clinicaltrials:fetch')->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => ($request->data()['filter.advanced'] ?? null) === 'AREA[LastUpdatePostDate]RANGE[2026-11-20,MAX]');
        $this->assertSame('2026-11-20', ImportRun::latest('id')->first()->updated_since->toDateString());
    }

    public function test_the_page_limit_records_a_partial_run(): void
    {
        $fixture = $this->studiesFixture();
        Http::fake([self::ENDPOINT => Http::response($this->page([$fixture['studies'][0]], 'more'))]);

        $this->artisan('clinicaltrials:fetch', ['--full' => true, '--max-pages' => 1])->assertSuccessful();

        Http::assertSentCount(1);
        $this->assertSame(ImportRunStatus::Partial, ImportRun::sole()->status);
    }

    public function test_invalid_options_are_rejected(): void
    {
        $this->artisan('clinicaltrials:fetch', ['--pageSize' => 0])->assertExitCode(2);
        $this->artisan('clinicaltrials:fetch', ['--since' => '20-11-2026'])->assertExitCode(2);

        Http::assertNothingSent();
    }

    public function test_the_queue_option_dispatches_the_job(): void
    {
        Queue::fake();

        $this->artisan('clinicaltrials:fetch', ['--queue' => true, '--since' => '2026-11-01'])->assertSuccessful();

        Queue::assertPushed(ImportClinicalTrialsJob::class, fn (ImportClinicalTrialsJob $job): bool => $job->options->updatedSince?->toDateString() === '2026-11-01');
    }

    public function test_alert_matching_is_queued_after_an_import(): void
    {
        Queue::fake([MatchAlertsJob::class]);
        Http::fake([self::ENDPOINT => Http::response($this->page([]))]);

        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();

        Queue::assertPushed(MatchAlertsJob::class, fn (MatchAlertsJob $job): bool => $job->run->is(ImportRun::sole()));
    }

    /**
     * @param  array<string, mixed>  $study
     * @return array<string, mixed>
     */
    private function changed(array $study): array
    {
        $study['protocolSection']['statusModule']['overallStatus'] = 'COMPLETED';
        $study['protocolSection']['contactsLocationsModule']['locations'] = [];
        $study['protocolSection']['conditionsModule']['conditions'] = [];

        return $study;
    }

    /**
     * @return array<string, int>
     */
    private function relationCounts(): array
    {
        return collect(ClinicalTrial::SYNCED_RELATIONS)
            ->mapWithKeys(fn (string $relation): array => [$relation => ClinicalTrial::query()->withCount($relation)->get()->sum(Str::snake($relation).'_count')])
            ->all();
    }
}
