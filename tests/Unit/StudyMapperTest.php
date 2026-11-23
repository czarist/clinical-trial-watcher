<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\InvalidStudyPayload;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Import\StudyMapper;
use PHPUnit\Framework\TestCase;

final class StudyMapperTest extends TestCase
{
    private StudyMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new StudyMapper;
    }

    public function test_it_maps_fields_and_returns_every_related_list(): void
    {
        $study = $this->study();
        $mapped = $this->mapper->map($study);

        $this->assertSame('NCT00000001', $mapped->nctId);
        $this->assertSame('2025-03-01', $mapped->attributes['primary_completion_date']);
        $this->assertSame('2024-01-01', $mapped->attributes['start_date']);
        $this->assertSame('PHASE1, PHASE2', $mapped->attributes['phase']);
        $this->assertTrue($mapped->attributes['has_results']);
        $this->assertSame($study, $mapped->attributes['raw_json']);
        $this->assertSame(ClinicalTrial::SYNCED_RELATIONS, array_keys($mapped->relations));
        $this->assertSame([['condition' => 'Asthma']], $mapped->relations['conditions']);
        $this->assertSame([], $mapped->relations['locations']);
        $this->assertCount(2, $mapped->relations['meshTerms']);
        $this->assertSame([true, false], array_column($mapped->relations['meshTerms'], 'is_primary'));
    }

    public function test_false_flags_stay_false_and_missing_modules_become_null(): void
    {
        $mapped = $this->mapper->map([
            'protocolSection' => ['identificationModule' => ['nctId' => 'NCT00000002', 'briefTitle' => 'Minimal']],
            'hasResults' => false,
        ]);

        $this->assertFalse($mapped->attributes['has_results']);
        $this->assertFalse($mapped->attributes['is_fda_regulated_drug']);
        $this->assertNull($mapped->attributes['overall_status']);
        $this->assertNull($mapped->attributes['phase']);
        $this->assertSame([], array_merge(...array_values($mapped->relations)));
    }

    public function test_rows_without_required_values_are_skipped_and_short_columns_truncated(): void
    {
        $study = $this->study();
        $study['protocolSection']['armsInterventionsModule']['interventions'] = [['type' => 'DRUG'], ['name' => 'Drug A']];
        $study['protocolSection']['conditionsModule']['keywords'] = [str_repeat('k', 300), '', 'k2', 'k2'];

        $mapped = $this->mapper->map($study);

        $this->assertSame(['Drug A'], array_column($mapped->relations['interventions'], 'name'));
        $this->assertSame([255, 2], array_map('strlen', array_column($mapped->relations['keywords'], 'keyword')));
    }

    public function test_a_study_without_nct_id_is_rejected(): void
    {
        $this->expectException(InvalidStudyPayload::class);

        $this->mapper->map(['protocolSection' => ['identificationModule' => ['briefTitle' => 'No id']]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function study(): array
    {
        return [
            'protocolSection' => [
                'identificationModule' => ['nctId' => 'NCT00000001', 'briefTitle' => 'A study'],
                'statusModule' => [
                    'overallStatus' => 'RECRUITING',
                    'startDateStruct' => ['date' => '2024'],
                    'primaryCompletionDateStruct' => ['date' => '2025-03'],
                ],
                'conditionsModule' => ['conditions' => ['Asthma']],
                'designModule' => ['phases' => ['PHASE1', 'PHASE2']],
            ],
            'derivedSection' => [
                'conditionBrowseModule' => [
                    'meshes' => [['id' => 'D001249', 'term' => 'Asthma']],
                    'ancestors' => [['id' => 'D008173', 'term' => 'Lung Diseases']],
                ],
            ],
            'hasResults' => true,
        ];
    }
}
