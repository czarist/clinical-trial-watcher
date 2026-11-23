<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ClinicalTrials\Changes\ChangeDetector;
use App\Services\ClinicalTrials\Changes\TrackedFacts;
use PHPUnit\Framework\TestCase;

final class ChangeDetectorTest extends TestCase
{
    public function test_reordering_sites_is_not_a_change(): void
    {
        $sites = [
            ['facility' => 'Hospital A', 'city' => 'São Paulo', 'country' => 'Brazil', 'status' => 'RECRUITING'],
            ['facility' => 'Clinic B', 'city' => 'Boston', 'country' => 'United States', 'status' => 'NOT_YET_RECRUITING'],
        ];

        $before = TrackedFacts::fromStudy($this->study(sites: $sites));
        $after = TrackedFacts::fromStudy($this->study(sites: array_reverse($sites)));

        $this->assertSame([], (new ChangeDetector)->diff($before, $after));
        $this->assertSame($before->hash(), $after->hash());
    }

    public function test_sites_added_removed_and_updated_are_described_with_their_status(): void
    {
        $before = TrackedFacts::fromStudy($this->study(sites: [
            ['facility' => 'Hospital A', 'city' => 'São Paulo', 'country' => 'Brazil', 'status' => 'NOT_YET_RECRUITING'],
            ['facility' => 'Clinic B', 'city' => 'Boston', 'country' => 'United States', 'status' => 'RECRUITING'],
        ]));
        $after = TrackedFacts::fromStudy($this->study(sites: [
            // Same site with different spacing and case: matched, status updated.
            ['facility' => 'hospital  a', 'city' => 'São Paulo', 'country' => 'Brazil', 'status' => 'RECRUITING'],
            ['facility' => 'Centro C', 'city' => 'Curitiba', 'country' => 'Brazil', 'status' => 'RECRUITING'],
        ]));

        $details = (new ChangeDetector)->diff($before, $after);

        $this->assertCount(3, $details);
        $byChange = collect($details)->keyBy('change');
        $this->assertSame(['NOT_YET_RECRUITING', 'RECRUITING'], [$byChange['updated']['before_status'], $byChange['updated']['after_status']]);
        $this->assertSame('Centro C', $byChange['added']['site']['facility']);
        $this->assertSame('RECRUITING', $byChange['added']['after_status']);
        $this->assertSame('Clinic B', $byChange['removed']['site']['facility']);
    }

    public function test_status_dates_with_original_precision_and_posted_results(): void
    {
        $before = TrackedFacts::fromStudy($this->study(status: 'NOT_YET_RECRUITING', primaryCompletion: '2027-06', hasResults: false));
        $after = TrackedFacts::fromStudy($this->study(status: 'RECRUITING', primaryCompletion: '2028-03', hasResults: true));

        $details = collect((new ChangeDetector)->diff($before, $after))->keyBy('field');

        $this->assertSame(['NOT_YET_RECRUITING', 'RECRUITING'], [$details['overall_status']['before'], $details['overall_status']['after']]);
        $this->assertSame('primary_completion_date', $details['date']['name']);
        $this->assertSame(['date' => '2027-06', 'type' => 'ESTIMATED'], $details['date']['before']);
        $this->assertSame(['date' => '2028-03', 'type' => 'ESTIMATED'], $details['date']['after']);
        $this->assertSame(['field' => 'results', 'before' => false, 'after' => true], $details['results']);
    }

    public function test_results_flag_reset_and_identical_facts_produce_no_detail(): void
    {
        $withResults = TrackedFacts::fromStudy($this->study(hasResults: true));

        $this->assertSame([], (new ChangeDetector)->diff($withResults, TrackedFacts::fromStudy($this->study(hasResults: true))));
        $this->assertSame([], (new ChangeDetector)->diff($withResults, TrackedFacts::fromStudy($this->study(hasResults: false))));
    }

    public function test_facts_round_trip_through_storage(): void
    {
        $facts = TrackedFacts::fromStudy($this->study(sites: [['facility' => 'Hospital A', 'country' => 'Brazil', 'status' => 'RECRUITING']]));

        $this->assertEquals($facts, TrackedFacts::fromArray(json_decode(json_encode($facts->toArray()), true)));
    }

    /**
     * @param  list<array<string, string>>  $sites
     * @return array<string, mixed>
     */
    private function study(string $status = 'RECRUITING', array $sites = [], string $primaryCompletion = '2027-06', bool $hasResults = false): array
    {
        return [
            'protocolSection' => [
                'identificationModule' => ['nctId' => 'NCT00000001'],
                'statusModule' => [
                    'overallStatus' => $status,
                    'startDateStruct' => ['date' => '2025', 'type' => 'ACTUAL'],
                    'primaryCompletionDateStruct' => ['date' => $primaryCompletion, 'type' => 'ESTIMATED'],
                ],
                'contactsLocationsModule' => ['locations' => $sites],
            ],
            'hasResults' => $hasResults,
        ];
    }
}
