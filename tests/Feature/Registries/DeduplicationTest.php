<?php

declare(strict_types=1);

namespace Tests\Feature\Registries;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Enums\TrialLinkStatus;
use App\Models\Alert;
use App\Models\AlertMatch;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialIdentifier;
use App\Models\ClinicalTrials\ClinicalTrialLink;
use App\Services\Registries\ClinicalTrialsGovSource;
use App\Services\Registries\SourceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Fakes\FakeRegistrySource;
use Tests\TestCase;

final class DeduplicationTest extends TestCase
{
    use RefreshDatabase;

    private const string ENDPOINT = 'https://clinicaltrials.gov/api/v2/studies*';

    private const string NCT_A = 'NCT90000001';

    private const string NCT_B = 'NCT90000002';

    private const string ISRCTN = 'ISRCTN12345678';

    /** @var list<array<string, mixed>> */
    private array $ctgov = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['registries.sources' => [
            ClinicalTrialsGovSource::KEY => ClinicalTrialsGovSource::class,
            FakeRegistrySource::KEY => FakeRegistrySource::class,
        ]]);
        $this->app->forgetInstance(SourceRegistry::class);
        FakeRegistrySource::reset();
        Http::preventStrayRequests();
        Http::fake([self::ENDPOINT => fn () => Http::response($this->page($this->ctgov))]);
        $this->seedAccessControl();
    }

    public function test_the_same_study_from_two_registries_is_shown_once_whatever_the_import_order(): void
    {
        $this->importFake([['id' => self::ISRCTN, 'title' => 'Same study, other registry', 'cites' => ['NCT 90000001']]]);
        $this->importCtgov([$this->ctgovStudy(self::NCT_A, cites: ['ISRCTN 12345678'])]);

        $nct = ClinicalTrial::where('source_id', self::NCT_A)->sole();
        $isrctn = ClinicalTrial::where('source_id', self::ISRCTN)->sole();

        // ClinicalTrials.gov has the higher priority, although the other record is older.
        $this->assertNull($nct->canonical_trial_id);
        $this->assertSame($nct->id, $isrctn->canonical_trial_id);
        $this->assertSame(TrialLinkStatus::Auto, ClinicalTrialLink::sole()->status);

        $this->actingAsApiUser($this->userWithRole())
            ->getJson('/api/trials')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.nct_id', self::NCT_A)
            ->assertJsonPath('data.0.source', ClinicalTrialsGovSource::KEY);

        $this->getJson('/api/trials/'.self::NCT_A)
            ->assertOk()
            ->assertJsonPath('data.also_registered_as.0.source_id', self::ISRCTN)
            ->assertJsonPath('data.also_registered_as.0.registry', 'Test Registry')
            ->assertJsonPath('data.also_registered_as.0.registry_url', 'https://registry.test/'.self::ISRCTN);

        // Filtering by the other registry still finds the study, through its other registration.
        $this->getJson('/api/trials?source[]='.FakeRegistrySource::KEY)->assertJsonCount(1, 'data')->assertJsonPath('data.0.nct_id', self::NCT_A);
        $this->getJson('/api/trials?source[]=unknown')->assertUnprocessable();
    }

    public function test_repeated_imports_keep_one_record_one_link_and_the_same_identifiers(): void
    {
        $fake = [['id' => self::ISRCTN, 'cites' => [self::NCT_A]]];
        $ctgov = [$this->ctgovStudy(self::NCT_A, cites: [self::ISRCTN, 'EudraCT 2019-001234-12'])];

        foreach (range(1, 2) as $round) {
            $this->importFake($fake);
            $this->importCtgov($ctgov);
        }

        $this->assertSame(2, ClinicalTrial::count());
        $this->assertSame(1, ClinicalTrialLink::count());
        $this->assertSame(
            ['eudract:2019-001234-12', 'isrctn:ISRCTN12345678', 'nct:NCT90000001'],
            ClinicalTrialIdentifier::where('clinical_trial_id', ClinicalTrial::where('source_id', self::NCT_A)->value('id'))
                ->get()->map(fn (ClinicalTrialIdentifier $i): string => $i->scheme->value.':'.$i->value)->sort()->values()->all(),
        );
    }

    public function test_two_records_of_one_registry_sharing_an_identifier_wait_for_review(): void
    {
        $this->importCtgov([
            $this->ctgovStudy(self::NCT_A, cites: ['2019-001234-12']),
            $this->ctgovStudy(self::NCT_B, cites: ['EudraCT 2019-001234-12']),
        ]);

        $this->assertSame(TrialLinkStatus::Conflict, ClinicalTrialLink::sole()->status);
        $this->assertSame(0, ClinicalTrial::whereNotNull('canonical_trial_id')->count());
        $this->actingAsApiUser($this->userWithRole())->getJson('/api/trials')->assertJsonCount(2, 'data');
    }

    public function test_a_group_that_would_hold_one_registry_twice_is_not_merged(): void
    {
        // A cites the other record, which cites B: A and B (both ClinicalTrials.gov) would end up together.
        $this->importCtgov([$this->ctgovStudy(self::NCT_A, cites: [self::ISRCTN]), $this->ctgovStudy(self::NCT_B)]);
        $this->importFake([['id' => self::ISRCTN, 'cites' => [self::NCT_B]]]);

        $this->assertSame(0, ClinicalTrial::whereNotNull('canonical_trial_id')->count());
        $this->assertSame([TrialLinkStatus::Conflict], ClinicalTrialLink::pluck('status')->unique()->values()->all());

        // Once the other registry stops citing B, A and the other record are grouped again.
        $this->importFake([['id' => self::ISRCTN, 'cites' => []]]);

        $this->assertSame(ClinicalTrial::where('source_id', self::NCT_A)->value('id'), ClinicalTrial::where('source_id', self::ISRCTN)->value('canonical_trial_id'));
        $this->assertSame(TrialLinkStatus::Auto, ClinicalTrialLink::sole()->status);
    }

    public function test_reviews_are_kept_by_later_imports(): void
    {
        $fake = [['id' => self::ISRCTN, 'cites' => [self::NCT_A]]];
        $this->importFake($fake);
        $this->importCtgov([$this->ctgovStudy(self::NCT_A)]);
        $link = ClinicalTrialLink::sole();
        $admin = $this->userWithRole(RoleName::Admin);

        $this->actingAsApiUser($this->userWithRole())->patchJson("/api/admin/trial-links/{$link->id}", ['status' => 'rejected'])->assertForbidden();
        $this->actingAsApiUser($admin)->getJson('/api/admin/trial-links?status=auto')->assertOk()->assertJsonPath('data.0.shared_identifier.value', self::NCT_A);
        $this->patchJson("/api/admin/trial-links/{$link->id}", ['status' => 'auto'])->assertUnprocessable();
        $this->patchJson("/api/admin/trial-links/{$link->id}", ['status' => 'rejected'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertSame(0, ClinicalTrial::whereNotNull('canonical_trial_id')->count());

        $this->importFake($fake);
        $this->importCtgov([$this->ctgovStudy(self::NCT_A)]);

        $this->assertSame(TrialLinkStatus::Rejected, $link->fresh()->status);
        $this->assertSame(0, ClinicalTrial::whereNotNull('canonical_trial_id')->count());
        $this->assertTrue($admin->hasPermission(PermissionName::ManageImports));
    }

    public function test_a_person_can_confirm_two_registrations_in_one_registry(): void
    {
        $this->importCtgov([$this->ctgovStudy(self::NCT_A, cites: ['2019-001234-12']), $this->ctgovStudy(self::NCT_B, cites: ['2019-001234-12'])]);

        $this->actingAsApiUser($this->userWithRole(RoleName::Admin))
            ->patchJson('/api/admin/trial-links/'.ClinicalTrialLink::sole()->id, ['status' => 'confirmed'])
            ->assertOk();

        $this->assertSame(ClinicalTrial::where('source_id', self::NCT_A)->value('id'), ClinicalTrial::where('source_id', self::NCT_B)->value('canonical_trial_id'));
    }

    public function test_an_automatic_link_disappears_when_the_shared_identifier_does(): void
    {
        $this->importFake([['id' => self::ISRCTN]]);
        $this->importCtgov([$this->ctgovStudy(self::NCT_A, cites: [self::ISRCTN])]);
        $this->assertSame(1, ClinicalTrial::whereNotNull('canonical_trial_id')->count());

        $this->importCtgov([$this->ctgovStudy(self::NCT_A)]);

        $this->assertSame(0, ClinicalTrialLink::count());
        $this->assertSame(0, ClinicalTrial::whereNotNull('canonical_trial_id')->count());
    }

    public function test_news_reported_by_a_second_registry_is_not_matched_or_sent_twice(): void
    {
        Notification::fake();
        $alert = Alert::factory()->instant()->for($this->userWithRole())->create([
            'criteria' => ['status' => ['RECRUITING']],
            'created_at' => now()->subDay(),
        ]);

        // The study appears and starts recruiting on ClinicalTrials.gov.
        $this->importCtgov([$this->ctgovStudy(self::NCT_A, status: 'NOT_YET_RECRUITING', cites: [self::ISRCTN])]);
        $this->importCtgov([$this->ctgovStudy(self::NCT_A, status: 'RECRUITING', cites: [self::ISRCTN])]);
        // Only the start of recruitment matches a search for recruiting studies.
        $this->assertSame(1, AlertMatch::where('alert_id', $alert->id)->count());
        Notification::assertCount(1);

        // The other registry lists it later, then reports the same status.
        $this->importFake([['id' => self::ISRCTN, 'status' => 'NOT_YET_RECRUITING']]);
        $this->importFake([['id' => self::ISRCTN, 'status' => 'RECRUITING']]);

        $nct = ClinicalTrial::where('source_id', self::NCT_A)->sole();
        $this->assertSame(1, AlertMatch::where('alert_id', $alert->id)->count());
        Notification::assertCount(1);
        $this->assertSame([$nct->id], AlertMatch::where('alert_id', $alert->id)->distinct()->pluck('clinical_trial_id')->all());

        // A real change reported only by the other registry is still news.
        $this->importFake([['id' => self::ISRCTN, 'status' => 'SUSPENDED']]);
        $this->assertSame(2, AlertMatch::where('alert_id', $alert->id)->count());
    }

    public function test_stored_records_are_reconciled_by_the_command(): void
    {
        $nct = ClinicalTrial::factory()->create(['nct_id' => self::NCT_A]);
        $nct->secondaryIds()->create(['secondary_id' => self::ISRCTN, 'type' => 'REGISTRY']);
        $other = ClinicalTrial::factory()->create(['source' => FakeRegistrySource::KEY, 'source_id' => self::ISRCTN, 'nct_id' => null]);

        $this->artisan('trials:reconcile-duplicates')->assertSuccessful();
        $this->artisan('trials:reconcile-duplicates')->assertSuccessful();

        $this->assertSame($nct->id, $other->fresh()->canonical_trial_id);
        $this->assertSame(1, ClinicalTrialLink::count());
        $this->artisan('trials:reconcile-duplicates', ['--source' => 'nope'])->assertExitCode(2);
    }

    public function test_the_import_command_rejects_an_unknown_registry(): void
    {
        $this->artisan('clinicaltrials:fetch', ['--source' => 'nope'])->assertExitCode(2);
    }

    /**
     * @param  list<array<string, mixed>>  $studies
     */
    private function importCtgov(array $studies): void
    {
        $this->ctgov = $studies;
        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     */
    private function importFake(array $payloads): void
    {
        FakeRegistrySource::$payloads = $payloads;
        $this->artisan('clinicaltrials:fetch', ['--full' => true, '--source' => FakeRegistrySource::KEY])->assertSuccessful();
    }

    /**
     * @param  list<string>  $cites
     * @return array<string, mixed>
     */
    private function ctgovStudy(string $nctId, string $status = 'RECRUITING', array $cites = []): array
    {
        $study = $this->studiesFixture()['studies'][0];
        $study['protocolSection']['identificationModule']['nctId'] = $nctId;
        $study['protocolSection']['identificationModule']['orgStudyIdInfo'] = ['id' => 'PROTOCOL-'.$nctId];
        $study['protocolSection']['identificationModule']['secondaryIdInfos'] = array_map(fn (string $id): array => ['id' => $id, 'type' => 'REGISTRY'], $cites);
        $study['protocolSection']['statusModule']['overallStatus'] = $status;

        return $study;
    }
}
