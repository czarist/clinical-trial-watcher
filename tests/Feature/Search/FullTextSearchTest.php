<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * InnoDB full-text indexes do not see uncommitted rows, so this test commits its data
 * and truncates the tables afterwards instead of using a transaction.
 */
final class FullTextSearchTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccessControl();
    }

    /**
     * Committed rows would leak into tests that run inside transactions.
     */
    protected function tearDown(): void
    {
        ClinicalTrial::query()->delete();
        User::query()->delete();

        parent::tearDown();
    }

    public function test_text_search_matches_titles_and_summaries_ordered_by_relevance(): void
    {
        $title = ClinicalTrial::factory()->create(['brief_title' => 'Inhaled corticosteroids in pediatric asthma', 'brief_summary' => 'Asthma control in children with asthma.']);
        $summary = ClinicalTrial::factory()->create(['brief_title' => 'Respiratory outcomes study', 'brief_summary' => 'Adults with asthma.']);
        ClinicalTrial::factory()->create(['brief_title' => 'Glucose control', 'brief_summary' => 'Type 2 diabetes.', 'official_title' => 'Glucose']);

        $ids = $this->actingAsApiUser($this->userWithRole())
            ->getJson('/api/trials?q=asthma&sort=relevance')
            ->assertOk()
            ->json('data.*.nct_id');

        $this->assertSame([$title->nct_id, $summary->nct_id], $ids);
    }
}
