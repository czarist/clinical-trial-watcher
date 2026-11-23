<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Cris;

use App\Enums\StudyPhase;
use App\Enums\StudyStatus;
use App\Enums\StudyType;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps a CRIS trial (its detail rows) to the common study model.
 *
 * CRIS publishes most fields in Korean (`_kr`) and English (`_en`); English is preferred.
 * Repeated fields (sites, arms, conditions, outcomes) may come as several rows of the same
 * trial or as lists, and are merged. Eligibility is limited to sex and age range. The
 * documented API has no field for other registries' identifiers; any field that looks like
 * one is kept only when it parses as a registry identifier. Names and telephone numbers of
 * investigators and contacts are personal data and are dropped, from the columns and from
 * the raw document.
 */
final class CrisMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    public const string COUNTRY = 'Korea, Republic of';

    /** Fields holding personal data, removed from the raw document. */
    private const array PERSONAL_FIELDS = [
        'scientific_name_kr', 'scientific_name_en', 'public_name_kr', 'public_name_en', 'telephone',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    #[\Override]
    public function payloadId(array $payload): ?string
    {
        foreach ($this->records($payload) as $record) {
            if (($id = self::normalizeId($record['trial_id'] ?? null)) !== null) {
                return $id;
            }
        }

        return null;
    }

    /** "KCT0002243", "kct 0002243" → KCT0002243; anything else → null. */
    public static function normalizeId(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/^\s*KCT\s*-?\s*(\d{7})\s*$/i', $value, $match) !== 1) {
            return null;
        }

        return 'KCT'.$match[1];
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidStudyPayload
     */
    #[\Override]
    public function map(array $payload): MappedStudy
    {
        $records = $this->records($payload);
        $sourceId = $this->payloadId($payload);

        if ($sourceId === null) {
            throw new InvalidStudyPayload('The CRIS record has no valid trial_id (KCT number).');
        }

        $title = $this->pick($records, 'public_title') ?? $this->pick($records, 'scientific_title');

        if ($title === null) {
            throw new InvalidStudyPayload("The CRIS record {$sourceId} has no title.");
        }

        $status = self::status($this->pick($records, 'recruitment_status_en') ?? $this->pick($records, 'recruitment_status_kr'));
        $studyType = self::studyType($this->pick($records, 'study_type'));
        $startDate = $this->date($this->pick($records, 'date_enrolment'));
        $completionDate = $this->date($this->pick($records, 'results_date_completed'));
        $enrollment = self::number($this->first($records, 'target_size'));
        [$minAge, $maxAge] = self::ageRange($this->pick($records, 'target_age_range_en') ?? $this->pick($records, 'target_age_range_kr'));
        $sponsor = $this->pick($records, 'primary_sponsor');
        $locations = $this->locations($records);

        $relations = $this->emptyRelations();
        $relations['secondaryIds'] = $this->secondaryIds($records, $sourceId);
        $relations['conditions'] = array_map(fn (string $condition): array => ['condition' => $condition], $this->all($records, 'hc_code'));
        $relations['interventions'] = $this->interventions($records);
        $relations['armGroups'] = array_map(
            fn (string $arm): array => ['label' => $arm, 'type' => null, 'description' => null],
            $this->all($records, 'arm_name'),
        );
        $relations['collaborators'] = array_map(
            fn (string $name): array => ['name' => $name, 'class' => null],
            array_values(array_filter($this->all($records, 'source_name'), fn (string $name): bool => $name !== $sponsor)),
        );
        $relations['locations'] = $locations;
        $relations['outcomes'] = [...$this->outcomes($records, 'primary', 'PRIMARY'), ...$this->outcomes($records, 'secondary', 'SECONDARY')];

        return new MappedStudy(
            source: CrisSource::KEY,
            sourceId: $sourceId,
            attributes: [
                'brief_title' => $title,
                'official_title' => $this->pick($records, 'scientific_title') ?? $title,
                'acronym' => null,
                'org_study_id' => null,
                'overall_status' => $status?->value,
                'status_verified_date' => null,
                'start_date' => $startDate,
                'primary_completion_date' => null,
                'completion_date' => $completionDate,
                'study_first_post_date' => $this->date($this->pick($records, 'date_registration')),
                'last_update_post_date' => $this->date($this->pick($records, 'date_updated')),
                'has_expanded_access' => false,
                'brief_summary' => $this->pickText($records, 'study_summary'),
                'detailed_description' => $this->design($records),
                'study_type' => $studyType,
                'phase' => self::phase($this->pick($records, 'phase')),
                'enrollment_count' => $enrollment,
                'enrollment_type' => $enrollment !== null ? 'ESTIMATED' : null,
                'sponsor_name' => $sponsor,
                'sponsor_class' => null,
                'responsible_party_type' => null,
                'eligibility_criteria' => null,
                'min_age' => $minAge,
                'max_age' => $maxAge,
                'gender' => self::gender($this->pick($records, 'target_gender_en') ?? $this->pick($records, 'target_gender_kr')),
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
                json_encode(['records' => array_map(self::withoutPersonalData(...), $records)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                is_string($payload['url'] ?? null) ? $payload['url'] : null,
            ),
        );
    }

    /** CRIS recruitment status, English or Korean, compared case- and space-insensitively. */
    public static function status(?string $value): ?StudyStatus
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower(trim((string) preg_replace('/[\s,]+/u', ' ', $value)));

        return match ($value) {
            'not yet recruiting', '모집 전', '모집전', '모집 예정', '모집예정' => StudyStatus::NotYetRecruiting,
            'recruiting', '모집 중', '모집중' => StudyStatus::Recruiting,
            'active not recruiting', 'recruitment completed', 'recruitment complete', '모집 완료', '모집완료' => StudyStatus::ActiveNotRecruiting,
            'enrolling by invitation' => StudyStatus::EnrollingByInvitation,
            'completed', 'study completed', '연구 종료', '연구종료', '종료' => StudyStatus::Completed,
            'suspended', 'temporarily halted', '일시 중지', '일시중지' => StudyStatus::Suspended,
            'terminated', 'stopped early', '조기 종료', '조기종료', '중단' => StudyStatus::Terminated,
            'withdrawn', '철회' => StudyStatus::Withdrawn,
            default => null,
        };
    }

    /** "Not applicable" → NA; "Phase 1/Phase 2", "Phase II", "2상" → PHASE values; phase 0 is early phase 1. */
    public static function phase(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = mb_strtolower($value);

        if (preg_match('#not applicable|n/a|해당\s*사항\s*없음|해당\s*없음#u', $value) === 1) {
            return StudyPhase::NotApplicable->value;
        }

        if (preg_match('/phase|상/u', $value) !== 1) {
            return null;
        }

        preg_match_all('/(?<![a-z0-9])(iv|iii|ii|i|[0-4])(?![a-z0-9])/', (string) preg_replace('/phase|임상|상/u', ' ', $value), $matches);
        $phases = array_values(array_unique(array_map(fn (string $number): string => match ($number) {
            '0' => StudyPhase::EarlyPhase1->value,
            '1', 'i' => StudyPhase::Phase1->value,
            '2', 'ii' => StudyPhase::Phase2->value,
            '3', 'iii' => StudyPhase::Phase3->value,
            default => StudyPhase::Phase4->value,
        }, $matches[1])));

        return $phases === [] ? null : implode(', ', $phases);
    }

    public static function studyType(?string $value): ?string
    {
        $value = mb_strtolower((string) $value);

        return match (true) {
            str_contains($value, 'interventional'), str_contains($value, '중재') => StudyType::Interventional->value,
            str_contains($value, 'observational'), str_contains($value, '관찰') => StudyType::Observational->value,
            default => null,
        };
    }

    public static function gender(?string $value): ?string
    {
        return match (mb_strtolower(trim((string) $value))) {
            'both', 'all', '둘다', '둘 다', '남녀 모두', '모두' => 'ALL',
            'female', '여', '여성' => 'FEMALE',
            'male', '남', '남성' => 'MALE',
            default => null,
        };
    }

    /**
     * "19 Year ~ No Limit", "6Month~18Year", "19 세 ~ 65 세" → ["19 Years", null], ["6 Months", "18 Years"], ...
     *
     * @return array{0: string|null, 1: string|null}
     */
    public static function ageRange(?string $value): array
    {
        if ($value === null) {
            return [null, null];
        }

        $parts = explode('~', $value, 2);

        return [self::age($parts[0]), self::age($parts[1] ?? '')];
    }

    private static function age(string $value): ?string
    {
        if (preg_match('/^\s*(\d+)\s*(year|month|week|day|hour|세|개월|주|일)?/iu', $value, $match) !== 1) {
            return null;
        }

        $unit = match (mb_strtolower($match[2] ?? '')) {
            'month', '개월' => 'Months',
            'week', '주' => 'Weeks',
            'day', '일' => 'Days',
            'hour' => 'Hours',
            default => 'Years',
        };

        return (int) $match[1] === 1 ? '1 '.rtrim($unit, 's') : $match[1].' '.$unit;
    }

    private static function number(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        return is_string($value) && preg_match('/^\s*(\d[\d,]*)\s*$/', $value, $match) === 1 ? (int) str_replace(',', '', $match[1]) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array<string, mixed>>
     */
    private function records(array $payload): array
    {
        $records = is_array($payload['records'] ?? null) ? $payload['records'] : [];

        return array_values(array_filter($records, static fn (mixed $record): bool => is_array($record) && ! array_is_list($record)));
    }

    /**
     * Scalar values of a field across the records (a field may itself be a list).
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<mixed>
     */
    private function values(array $records, string $field): array
    {
        $values = [];

        foreach ($records as $record) {
            $value = $record[$field] ?? null;

            foreach (is_array($value) ? $value : [$value] as $entry) {
                if (is_scalar($entry)) {
                    $values[] = $entry;
                }
            }
        }

        return $values;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function first(array $records, string $field): mixed
    {
        return $this->values($records, $field)[0] ?? null;
    }

    /**
     * First non-empty value of `{field}_en`, then `{field}_kr`, or of the field itself when it has no language suffix.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function pick(array $records, string $field): ?string
    {
        foreach (str_ends_with($field, '_en') || str_ends_with($field, '_kr') ? [$field] : ["{$field}_en", "{$field}_kr", $field] as $name) {
            foreach ($this->values($records, $name) as $value) {
                if (($value = $this->short($value)) !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function pickText(array $records, string $field): ?string
    {
        foreach (["{$field}_en", "{$field}_kr"] as $name) {
            foreach ($this->values($records, $name) as $value) {
                if (($value = $this->text($value)) !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Distinct values of a bilingual repeated field: per record, English when given, else Korean.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<string>
     */
    private function all(array $records, string $field, int $length = 255): array
    {
        $values = [];

        foreach ($records as $record) {
            $english = array_values(array_filter(array_map(fn (mixed $value): ?string => $this->short($value, $length), $this->values([$record], "{$field}_en"))));
            $korean = array_values(array_filter(array_map(fn (mixed $value): ?string => $this->short($value, $length), $this->values([$record], "{$field}_kr"))));

            foreach ($english !== [] ? $english : $korean as $value) {
                $values[$value] = $value;
            }
        }

        return array_values($values);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function locations(array $records): array
    {
        $sites = $this->all($records, 'site_name');

        return array_map(fn (?string $site): array => ['facility' => $site, 'city' => null, 'state' => null, 'country' => self::COUNTRY,
            'status' => null, 'zip' => null, 'latitude' => null, 'longitude' => null], $sites === [] ? [null] : $sites);
    }

    /**
     * One intervention per record (or list entry): its free-text description, typed when the type is recognised.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, string|null>>
     */
    private function interventions(array $records): array
    {
        $rows = [];

        foreach ($records as $record) {
            $type = self::interventionType($this->pick([$record], 'i_freetext_type'));
            $description = $this->pickText([$record], 'i_freetext');
            $name = $this->short($description) ?? $this->pick([$record], 'i_freetext_type');

            if ($name !== null) {
                $rows[$name] ??= ['type' => $type, 'name' => $name, 'description' => $description];
            }
        }

        return array_values($rows);
    }

    private static function interventionType(?string $value): ?string
    {
        $value = mb_strtolower((string) $value);

        return match (true) {
            $value === '' => null,
            str_contains($value, 'combination') => 'COMBINATION_PRODUCT',
            str_contains($value, 'device'), str_contains($value, '의료기') => 'DEVICE',
            str_contains($value, 'drug'), str_contains($value, '의약품') => 'DRUG',
            str_contains($value, 'biolog'), str_contains($value, '생물') => 'BIOLOGICAL',
            str_contains($value, 'procedure'), str_contains($value, 'surgery'), str_contains($value, '시술'), str_contains($value, '수술') => 'PROCEDURE',
            str_contains($value, 'behavio'), str_contains($value, '행동') => 'BEHAVIORAL',
            str_contains($value, 'dietary'), str_contains($value, '식이') => 'DIETARY_SUPPLEMENT',
            str_contains($value, 'radiation'), str_contains($value, '방사선') => 'RADIATION',
            str_contains($value, 'genetic'), str_contains($value, '유전') => 'GENETIC',
            default => 'OTHER',
        };
    }

    /**
     * Outcomes from `{kind}_outcome_{en|kr}` and numbered `{kind}_outcome_{n}_{en|kr}` fields,
     * with their time frame (`{kind}_outcome_time_*`) when given.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, string|null>>
     */
    private function outcomes(array $records, string $kind, string $type): array
    {
        $rows = [];

        foreach ($records as $record) {
            $fields = [];

            foreach (array_keys($record) as $key) {
                if (is_string($key) && preg_match("/^{$kind}_outcome(_\d+)?_(en|kr)$/", $key, $match) === 1) {
                    $fields[$match[1] ?? ''] = true;
                }
            }

            foreach (array_keys($fields) as $number) {
                $measures = $this->all([$record], "{$kind}_outcome{$number}", 4000);
                $time = $number === '' ? $this->pick([$record], "{$kind}_outcome_time") : null;

                foreach ($measures as $measure) {
                    $rows[$measure] ??= ['type' => $type, 'measure' => $this->short($measure), 'description' => $measure, 'time_frame' => $time];
                }
            }
        }

        return array_values($rows);
    }

    /**
     * Design fields as labelled lines.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private function design(array $records): ?string
    {
        $lines = [];

        foreach ([
            'primary_purpose' => 'Primary purpose',
            'allocation' => 'Allocation',
            'intervention_model' => 'Intervention model',
            'blinding_masking' => 'Masking',
            'blinding_masking_target' => 'Masked roles',
            'time_perspective' => 'Time perspective',
            'biospecimen_retention' => 'Biospecimen retention',
        ] as $field => $label) {
            if (($value = $this->pick($records, $field)) !== null) {
                $lines[] = "{$label}: {$value}";
            }
        }

        if (($groups = $this->short($this->first($records, 'group_number'))) !== null) {
            $lines[] = "Number of groups: {$groups}";
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * Registry identifiers in fields that may hold them (none are documented); only values
     * that parse as a registry identifier are kept, and never the record's own KCT number.
     *
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, string|null>>
     */
    private function secondaryIds(array $records, string $sourceId): array
    {
        $parser = new IdentifierParser;
        $pairs = [];

        foreach ($records as $record) {
            foreach ($record as $key => $value) {
                if (! is_string($key) || preg_match('/secondary_id|other_id|registry_id|nct/i', $key) !== 1) {
                    continue;
                }

                foreach (is_array($value) ? $value : [$value] as $entry) {
                    if (is_string($entry) && self::normalizeId($entry) !== $sourceId && $parser->parse($entry) !== null) {
                        $pairs[] = [$entry, null];
                    }
                }
            }
        }

        return $this->secondaryIdRows($pairs);
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private static function withoutPersonalData(array $record): array
    {
        foreach (array_keys($record) as $key) {
            if (in_array($key, self::PERSONAL_FIELDS, true) || (is_string($key) && preg_match('/e_?mail|phone|fax|mobile/i', $key) === 1)) {
                unset($record[$key]);
            }
        }

        return $record;
    }
}
