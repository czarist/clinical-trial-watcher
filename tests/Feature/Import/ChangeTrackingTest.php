<?php

declare(strict_types=1);

namespace Tests\Feature\Import;

use App\Enums\TrialChangeType;
use App\Models\Alert;
use App\Models\AlertMatch;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialChange;
use App\Models\ImportRun;
use App\Services\Alerts\AlertMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class ChangeTrackingTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = 'https://clinicaltrials.gov/api/v2/studies*';

    public function test_a_new_study_is_a_baseline_and_an_identical_import_records_nothing(): void
    {
        $study = $this->studiesFixture()['studies'][0];
        $this->importTwice($study, $study);

        $trial = ClinicalTrial::sole();
        $this->assertSame(1, ClinicalTrialChange::count());
        $this->assertSame(TrialChangeType::Created, $trial->trialChanges()->sole()->type);
        $this->assertNull($trial->trialChanges()->sole()->details);
        $this->assertNotEmpty($trial->tracked_facts['sites']);
        $this->assertSame('RECRUITING', $trial->locations()->first()->status);
    }

    public function test_reordered_sites_do_not_create_a_change(): void
    {
        $study = $this->studiesFixture()['studies'][0];
        $reordered = $study;
        $reordered['protocolSection']['contactsLocationsModule']['locations'] = array_reverse($study['protocolSection']['contactsLocationsModule']['locations']);

        $this->importTwice($study, $reordered);

        $this->assertSame(1, ClinicalTrialChange::count());
        $this->assertSame(1, ImportRun::latest('id')->first()->studies_unchanged);
    }

    public function test_status_sites_dates_and_results_are_recorded_before_and_after(): void
    {
        $study = $this->studiesFixture()['studies'][0];
        $study['protocolSection']['statusModule']['overallStatus'] = 'NOT_YET_RECRUITING';
        $changed = $study;
        $changed['protocolSection']['statusModule']['overallStatus'] = 'RECRUITING';
        $changed['protocolSection']['statusModule']['primaryCompletionDateStruct'] = ['date' => '2028-03', 'type' => 'ESTIMATED'];
        $changed['protocolSection']['contactsLocationsModule']['locations'][] = [
            'facility' => 'Hospital Exemplo', 'city' => 'Curitiba', 'country' => 'Brazil', 'status' => 'RECRUITING',
        ];
        $changed['protocolSection']['contactsLocationsModule']['locations'][0]['status'] = 'COMPLETED';
        $changed['hasResults'] = true;

        $this->importTwice($study, $changed);

        $change = ClinicalTrialChange::latest('id')->first();
        $this->assertSame(TrialChangeType::StatusChanged, $change->type);
        $this->assertNotNull($change->fingerprint);

        $details = collect($change->details);
        $this->assertSame(['NOT_YET_RECRUITING', 'RECRUITING'], [
            $details->firstWhere('field', 'overall_status')['before'],
            $details->firstWhere('field', 'overall_status')['after'],
        ]);
        $this->assertSame('2028-03', $details->firstWhere('field', 'date')['after']['date']);
        $this->assertNotNull($details->firstWhere('field', 'results'));
        $this->assertSame('Hospital Exemplo', $details->where('field', 'site')->firstWhere('change', 'added')['site']['facility']);
        $this->assertSame('COMPLETED', $details->where('field', 'site')->firstWhere('change', 'updated')['after_status']);
    }

    public function test_a_site_status_change_alone_is_an_update(): void
    {
        $study = $this->studiesFixture()['studies'][0];
        $changed = $study;
        $changed['protocolSection']['contactsLocationsModule']['locations'][0]['status'] = 'ACTIVE_NOT_RECRUITING';

        $this->importTwice($study, $changed);

        $change = ClinicalTrialChange::latest('id')->first();
        $this->assertSame(TrialChangeType::Updated, $change->type);
        $this->assertSame([['field' => 'site', 'change' => 'updated']], collect($change->details)->map(fn (array $d): array => ['field' => $d['field'], 'change' => $d['change']])->all());
    }

    public function test_a_study_imported_before_tracking_gets_a_baseline_without_invented_details(): void
    {
        $study = $this->studiesFixture()['studies'][0];
        $withoutSites = $study;
        $withoutSites['protocolSection']['contactsLocationsModule']['locations'] = [];
        $withResults = $withoutSites;
        $withResults['hasResults'] = true;

        Http::fake([self::ENDPOINT => Http::sequence()
            ->push($this->page([$study]))
            ->push($this->page([$withoutSites]))
            ->push($this->page([$withResults]))]);
        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();
        ClinicalTrial::query()->update(['tracked_facts' => null]);

        // Without a snapshot the removed sites cannot be told apart from earlier data: baseline only.
        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();
        $this->assertSame(1, ClinicalTrialChange::count());
        $this->assertSame([], ClinicalTrial::sole()->tracked_facts['sites']);

        // The next import compares against the baseline.
        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();
        $this->assertEquals([['field' => 'results', 'before' => false, 'after' => true]], ClinicalTrialChange::latest('id')->first()->details);
    }

    public function test_matching_the_same_run_twice_does_not_duplicate_matches_or_notifications(): void
    {
        Notification::fake();
        $this->seedAccessControl();
        $alert = Alert::factory()->instant()->for($this->userWithRole())->create([
            'criteria' => ['status' => ['RECRUITING']],
            'created_at' => now()->subDay(),
        ]);
        $study = $this->studiesFixture()['studies'][0];
        Http::fake([self::ENDPOINT => Http::response($this->page([$study]))]);
        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();
        $run = ImportRun::sole();
        $matcher = app(AlertMatcher::class);

        $matcher->matchImportRun($run);
        $this->assertSame(0, $matcher->matchImportRun($run));

        $this->assertSame(1, AlertMatch::where('alert_id', $alert->id)->count());
        Notification::assertCount(1);
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     */
    private function importTwice(array $first, array $second): void
    {
        Http::fake([self::ENDPOINT => Http::sequence()->push($this->page([$first]))->push($this->page([$second]))]);

        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();
        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();
    }
}
