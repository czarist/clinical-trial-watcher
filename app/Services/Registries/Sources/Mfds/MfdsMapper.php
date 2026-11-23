<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Mfds;

use App\Enums\StudyPhase;
use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps an MFDS approved clinical trial plan to the common study model.
 *
 * Every plan is an interventional drug trial run in South Korea. The API publishes no
 * eligibility criteria, no sponsor (only the original developer, kept as a collaborator) and
 * no other registry's identifier, so these records cannot be linked to their registrations
 * elsewhere. Titles prefer English (brief title) and keep Korean (official title).
 */
final class MfdsMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    public const string COUNTRY = 'Korea, Republic of';

    /**
     * STATUS (진행현황) values, compared without spaces. The vocabulary is not documented; "진행중"
     * (in progress) does not say whether the trial still recruits, so it has no equivalent.
     */
    private const array STATUSES = [
        '모집전' => StudyStatus::NotYetRecruiting,
        '모집예정' => StudyStatus::NotYetRecruiting,
        '모집중' => StudyStatus::Recruiting,
        '모집완료' => StudyStatus::ActiveNotRecruiting,
        '완료' => StudyStatus::Completed,
        '종료' => StudyStatus::Completed,
        '시험완료' => StudyStatus::Completed,
        '시험종료' => StudyStatus::Completed,
        '임상시험종료' => StudyStatus::Completed,
        '조기종료' => StudyStatus::Terminated,
        '중단' => StudyStatus::Terminated,
        '중지' => StudyStatus::Suspended,
        '일시중지' => StudyStatus::Suspended,
        '철회' => StudyStatus::Withdrawn,
        '취소' => StudyStatus::Withdrawn,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    #[\Override]
    public function payloadId(array $payload): ?string
    {
        $item = is_array($payload['item'] ?? null) ? $payload['item'] : [];
        $id = $item['CLNC_TEST_SN'] ?? null;

        if (! is_int($id) && ! is_string($id)) {
            return null;
        }

        $id = trim((string) $id);

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $id) === 1 ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidStudyPayload
     */
    #[\Override]
    public function map(array $payload): MappedStudy
    {
        $item = is_array($payload['item'] ?? null) ? $payload['item'] : [];
        $sourceId = $this->payloadId($payload);

        if ($sourceId === null) {
            throw new InvalidStudyPayload('The MFDS clinical trial plan has no CLNC_TEST_SN.');
        }

        $englishTitle = $this->short($item['CLNC_TEST_ENG_TITLE'] ?? null);
        $koreanTitle = $this->short($item['CLNC_TEST_TITLE'] ?? null);

        if ($englishTitle === null && $koreanTitle === null) {
            throw new InvalidStudyPayload("The MFDS clinical trial plan {$sourceId} has no title.");
        }

        $status = self::status($item['STATUS'] ?? null);
        $startDate = $this->mfdsDate($item['CLST_BGNG_DT'] ?? null);
        $completionDate = $this->mfdsDate($item['TEST_COMPLETION_DT'] ?? null) ?? $this->mfdsDate($item['CLST_END_DT'] ?? null);
        $approvalDate = $this->mfdsDate($item['CLST_APRV_DT'] ?? null);
        $enrollment = self::count($item['TESTEE_CNT_INFO'] ?? null);
        $locations = $this->locations($item['ADDR'] ?? null);

        $relations = $this->emptyRelations();
        $relations['conditions'] = array_map(fn (string $condition): array => ['condition' => $condition], $this->distinct([
            $this->short($item['TRGT_DISS_NM'] ?? null),
            $this->short($item['TRGT_DISS_DTL_CD_NM'] ?? null),
            $this->short($item['TRGT_DISS_DTL_ETC'] ?? null),
        ]));
        $relations['keywords'] = array_map(fn (string $keyword): array => ['keyword' => $keyword], $this->distinct([
            $this->short($item['TRGT_DISS_CD_NM'] ?? null),
        ]));
        $relations['interventions'] = $this->interventions($item);
        $relations['collaborators'] = ($developer = $this->short($item['ORGN_DEV_BSSH_NM'] ?? null)) !== null
            ? [['name' => $developer, 'class' => null]]
            : [];
        $relations['locations'] = $locations;
        $relations['outcomes'] = array_values(array_filter([
            $this->outcome('PRIMARY', $item['PRIM_EVL_VAR'] ?? null),
            $this->outcome('SECONDARY', $item['SCND_EVL_VAR'] ?? null),
        ]));

        return new MappedStudy(
            source: MfdsSource::KEY,
            sourceId: $sourceId,
            attributes: [
                'brief_title' => $englishTitle ?? $koreanTitle,
                'official_title' => $koreanTitle ?? $englishTitle,
                'acronym' => null,
                'org_study_id' => null,
                'overall_status' => $status?->value,
                'status_verified_date' => null,
                'start_date' => $startDate,
                'primary_completion_date' => null,
                'completion_date' => $completionDate,
                'study_first_post_date' => $approvalDate,
                'last_update_post_date' => $this->mfdsDate($item['HIST_REG_DT'] ?? null) ?? $approvalDate,
                'has_expanded_access' => false,
                'brief_summary' => $this->text($item['IVSGT_PURPS'] ?? null),
                'detailed_description' => $this->design($item),
                'study_type' => 'INTERVENTIONAL',
                'phase' => self::phase($item['CLINIC_STEP_NM'] ?? null),
                'enrollment_count' => $enrollment,
                'enrollment_type' => $enrollment !== null ? 'ESTIMATED' : null,
                'sponsor_name' => null,
                'sponsor_class' => null,
                'responsible_party_type' => null,
                'eligibility_criteria' => null,
                'min_age' => null,
                'max_age' => null,
                'gender' => null,
                'is_fda_regulated_drug' => false,
                'is_fda_regulated_device' => false,
                'ipd_sharing' => null,
                'has_results' => false,
            ],
            relations: $relations,
            facts: $this->facts($status?->value, $locations, [
                'start_date' => $startDate,
                'primary_completion_date' => null,
                'completion_date' => $completionDate,
            ]),
            raw: new RawDocument(
                'json',
                json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                is_string($payload['url'] ?? null) ? $payload['url'] : null,
            ),
        );
    }

    public static function status(mixed $value): ?StudyStatus
    {
        if (! is_string($value)) {
            return null;
        }

        return self::STATUSES[(string) preg_replace('/\s+/u', '', $value)] ?? null;
    }

    /** "1상", "제1/2상", "2b상", "Phase 3" → PHASE values; "기타" (other) or a bioequivalence study → null. */
    public static function phase(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = mb_strtolower((string) preg_replace('/\s+/u', '', $value));

        if (preg_match('/상|phase/u', $value) !== 1) {
            return null;
        }

        preg_match_all('/(?<![0-9])([0-4])(?![0-9])/', str_replace('임상', '', $value), $matches);
        $phases = array_values(array_unique(array_map(fn (string $number): string => match ($number) {
            '0' => StudyPhase::EarlyPhase1->value,
            '1' => StudyPhase::Phase1->value,
            '2' => StudyPhase::Phase2->value,
            '3' => StudyPhase::Phase3->value,
            default => StudyPhase::Phase4->value,
        }, $matches[1])));

        return $phases === [] ? null : implode(', ', $phases);
    }

    /** A target number of subjects written as text ("100", "국내 1,200명"); null unless exactly one number is given. */
    public static function count(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || preg_match_all('/\d[\d,]*/', $value, $matches) !== 1) {
            return null;
        }

        return (int) str_replace(',', '', $matches[0][0]);
    }

    /** "20240115", "2024.01.15", "2024/01/15" or an ISO date as YYYY-MM-DD. */
    private function mfdsDate(mixed $value): ?string
    {
        $value = is_int($value) ? (string) $value : $value;

        if (is_string($value) && preg_match('#^\s*(\d{4})[./]?(\d{2})[./]?(\d{2})\.?\s*$#', $value, $match) === 1) {
            $value = "{$match[1]}-{$match[2]}-{$match[3]}";
        }

        return $this->date($value);
    }

    /**
     * ADDR (임상시험 소재지) is free text; several sites are separated by commas, semicolons or
     * line breaks. Without a site, the country alone is recorded.
     *
     * @return list<array<string, mixed>>
     */
    private function locations(mixed $address): array
    {
        $sites = is_string($address) ? $this->distinct(array_map(
            fn (string $site): ?string => $this->short($site),
            preg_split('/[,;\n\r]+/u', $address) ?: [],
        )) : [];

        if ($sites === []) {
            $sites = [null];
        }

        return array_map(fn (?string $site): array => ['facility' => $site, 'city' => null, 'state' => null, 'country' => self::COUNTRY,
            'status' => null, 'zip' => null, 'latitude' => null, 'longitude' => null], $sites);
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<array<string, string|null>>
     */
    private function interventions(array $item): array
    {
        $rows = [];
        $product = $this->short($item['PRDT_NM'] ?? null) ?? $this->short($item['GNRL_CD_NM'] ?? null);

        if ($product !== null) {
            $details = array_filter([
                ($ingredient = $this->short($item['GNRL_CD_NM'] ?? null)) !== null ? "Ingredient: {$ingredient}" : null,
                ($shape = $this->short($item['SHAPE_CD'] ?? null)) !== null ? "Dosage form: {$shape}" : null,
                ($method = $this->short($item['CLST_MTH_INFO'] ?? null, 1000)) !== null ? "Administration: {$method}" : null,
                ($term = $this->short($item['DOSAGE_TERM_TS'] ?? null)) !== null ? "Duration: {$term}" : null,
            ]);
            $rows[$product] = ['type' => 'DRUG', 'name' => $product, 'description' => $details === [] ? null : implode('; ', $details)];
        }

        $comparator = $this->short($item['COMP_DRUG_NM'] ?? null);

        if ($comparator !== null) {
            $rows[$comparator] ??= ['type' => 'DRUG', 'name' => $comparator, 'description' => 'Comparator'];
        }

        if (self::yes($item['PLCB_YN'] ?? null)) {
            $rows['Placebo'] ??= ['type' => 'DRUG', 'name' => 'Placebo', 'description' => null];
        }

        return array_values($rows);
    }

    /**
     * @return array<string, string|null>|null
     */
    private function outcome(string $type, mixed $value): ?array
    {
        $measure = $this->short($value);

        return $measure === null ? null : ['type' => $type, 'measure' => $measure, 'description' => $this->text($value), 'time_frame' => null];
    }

    /**
     * The design fields as labelled lines (values stay in Korean, as published).
     *
     * @param  array<string, mixed>  $item
     */
    private function design(array $item): ?string
    {
        $lines = [];

        foreach ([
            'CLINIC_TYPE_CD_NM' => 'Trial type',
            'CLST_PRPOS_CD_NM' => 'Purpose of use',
            'RNDM_ASSG_CD_NM' => 'Randomization',
            'BLND_CD_NM' => 'Blinding',
            'ARBTR_GRP_NUM' => 'Number of arms',
            'DMINT_DIVS_CD_NM' => 'Domestic or foreign development',
            'CLNC_TEST_DTL_CD_NM' => 'Multinational',
            'CLINICAL_PERIOD' => 'Period',
            'ORGN_DEV_NATN_CD_NM' => 'Original developer country',
        ] as $field => $label) {
            $value = $this->short($item[$field] ?? null, 1000);

            if ($value !== null) {
                $lines[] = "{$label}: {$value}";
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    private static function yes(mixed $value): bool
    {
        return is_string($value) && strtoupper(trim($value)) === 'Y';
    }

    /**
     * @param  list<string|null>  $values
     * @return list<string>
     */
    private function distinct(array $values): array
    {
        return array_values(array_unique(array_filter($values, fn (?string $value): bool => $value !== null)));
    }
}
