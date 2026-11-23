<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Enums\RoleName;
use App\Enums\StudyPhase;
use App\Enums\StudyStatus;
use App\Enums\TrialChangeType;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialCondition;
use App\Models\ClinicalTrials\ClinicalTrialLocation;
use App\Models\ClinicalTrials\ClinicalTrialMeshTerm;
use App\Models\ClinicalTrials\ClinicalTrialReference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SearchTrialsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccessControl();
        $this->user = $this->userWithRole();
    }

    public function test_search_requires_authentication_and_permission(): void
    {
        $this->getJson('/api/trials')->assertUnauthorized();

        $withoutRole = User::factory()->create();
        $this->actingAsApiUser($withoutRole)->getJson('/api/trials')->assertForbidden();
    }

    public function test_results_are_paginated_with_a_disclaimer(): void
    {
        ClinicalTrial::factory()->count(3)->create();

        $this->actingAsApiUser($this->user)->getJson('/api/trials?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonStructure(['data' => [['nct_id', 'brief_title', 'overall_status', 'phases', 'conditions', 'locations_count', 'registry_url']], 'meta' => ['disclaimer'], 'links']);
    }

    public function test_client_controlled_limits_and_sorts_are_validated(): void
    {
        $this->actingAsApiUser($this->user);

        $this->getJson('/api/trials?per_page=500')->assertUnprocessable()->assertJsonValidationErrors('per_page');
        $this->getJson('/api/trials?sort=raw_json')->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson('/api/trials?status[]=DROP_TABLE')->assertUnprocessable()->assertJsonValidationErrors('status.0');
        $this->getJson('/api/trials?latitude=10')->assertUnprocessable()->assertJsonValidationErrors(['longitude', 'radius_km']);
    }

    public function test_filters_by_status_type_sponsor_and_dates(): void
    {
        $match = ClinicalTrial::factory()->status(StudyStatus::Recruiting)->create([
            'sponsor_name' => 'Acme Pharma', 'start_date' => '2025-03-10', 'last_update_post_date' => '2026-10-01',
        ]);
        ClinicalTrial::factory()->status(StudyStatus::Completed)->create(['sponsor_name' => 'Acme Pharma']);
        ClinicalTrial::factory()->status(StudyStatus::Recruiting)->create(['sponsor_name' => 'Other', 'start_date' => '2020-01-01']);

        $this->assertNctIds([$match->nct_id], '/api/trials?status[]=RECRUITING&sponsor=acme&start_date_from=2025-01-01&last_update_to=2026-12-31');
    }

    public function test_phase_filter_matches_whole_phases(): void
    {
        $phase1 = ClinicalTrial::factory()->phases([StudyPhase::Phase1, StudyPhase::Phase2])->create();
        ClinicalTrial::factory()->phases([StudyPhase::EarlyPhase1])->create();

        $this->assertNctIds([$phase1->nct_id], '/api/trials?phase[]=PHASE1');
    }

    public function test_filters_by_condition_keyword_and_mesh_term(): void
    {
        $match = ClinicalTrial::factory()->create();
        ClinicalTrialCondition::factory()->for($match, 'trial')->create(['condition' => 'Severe Asthma']);
        ClinicalTrialMeshTerm::factory()->for($match, 'trial')->create(['term' => 'Lung Diseases']);
        $other = ClinicalTrial::factory()->create();
        ClinicalTrialCondition::factory()->for($other, 'trial')->create(['condition' => 'Diabetes']);

        $this->assertNctIds([$match->nct_id], '/api/trials?condition=asthma');
        $this->assertNctIds([$match->nct_id], '/api/trials?mesh_term=lung');
        $this->assertNctIds([], '/api/trials?condition=100%25');
    }

    public function test_filters_by_country_city_and_distance(): void
    {
        $saoPaulo = ClinicalTrial::factory()->create();
        ClinicalTrialLocation::factory()->for($saoPaulo, 'trial')->create([
            'country' => 'Brazil', 'city' => 'São Paulo', 'latitude' => -23.5505, 'longitude' => -46.6333,
        ]);
        $rio = ClinicalTrial::factory()->create();
        ClinicalTrialLocation::factory()->for($rio, 'trial')->create([
            'country' => 'Brazil', 'city' => 'Rio de Janeiro', 'latitude' => -22.9068, 'longitude' => -43.1729,
        ]);

        $this->assertNctIds([$saoPaulo->nct_id, $rio->nct_id], '/api/trials?country=Brazil&sort=brief_title', ordered: false);
        $this->assertNctIds([$rio->nct_id], '/api/trials?country=Brazil&city=rio');
        // Campinas is about 85 km from São Paulo and 400 km from Rio de Janeiro.
        $this->assertNctIds([$saoPaulo->nct_id], '/api/trials?latitude=-22.9099&longitude=-47.0626&radius_km=150');
    }

    public function test_detail_returns_every_related_list_with_pubmed_links_and_status_history(): void
    {
        $trial = ClinicalTrial::factory()->create(['nct_id' => 'NCT01234567']);
        ClinicalTrialReference::factory()->for($trial, 'trial')->create(['pmid' => '12345678']);
        ClinicalTrialLocation::factory()->for($trial, 'trial')->create();
        $trial->trialChanges()->create(['type' => TrialChangeType::StatusChanged, 'previous_status' => 'NOT_YET_RECRUITING', 'new_status' => 'RECRUITING']);

        $this->actingAsApiUser($this->user)->getJson('/api/trials/nct01234567')
            ->assertOk()
            ->assertJsonPath('data.nct_id', 'NCT01234567')
            ->assertJsonPath('data.references.0.pubmed_url', 'https://pubmed.ncbi.nlm.nih.gov/12345678/')
            ->assertJsonPath('data.status_history.0.new_status', 'RECRUITING')
            ->assertJsonCount(1, 'data.locations')
            ->assertJsonStructure(['data' => ['keywords', 'mesh_terms', 'secondary_ids', 'collaborators', 'interventions', 'arm_groups', 'outcomes', 'eligibility'], 'meta' => ['disclaimer']])
            ->assertJsonMissingPath('data.raw_json');
    }

    public function test_unknown_and_malformed_study_ids_return_404(): void
    {
        $this->actingAsApiUser($this->user);

        $this->getJson('/api/trials/NCT99999999')->assertNotFound();
        $this->getJson('/api/trials/not-an-id')->assertNotFound();
    }

    public function test_admins_can_search_too(): void
    {
        ClinicalTrial::factory()->create();

        $this->actingAsApiUser($this->userWithRole(RoleName::Admin))->getJson('/api/trials')->assertOk()->assertJsonCount(1, 'data');
    }

    /**
     * @param  list<string>  $expected
     */
    private function assertNctIds(array $expected, string $uri, bool $ordered = true): void
    {
        $actual = $this->actingAsApiUser($this->user)->getJson($uri)->assertOk()->json('data.*.nct_id');

        if (! $ordered) {
            sort($expected);
            sort($actual);
        }

        $this->assertSame($expected, $actual, $uri);
    }
}
