<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\NciCts;

use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Identifiers\IdentifierParser;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps one trial document of the NCI Clinical Trials Search API v2.
 *
 * Field names and shapes follow NCI's own mock trial documents (NCIOCPL/clinical-trials-search-app,
 * support/mock-data/clinical-trial/NCI-2020-08118.json): nci_id, nct_id, protocol_id, phase.phase,
 * current_trial_status, study_protocol_type, diseases[], arms[].interventions[], sites[],
 * eligibility.structured/unstructured, outcome_measures[], record_verification_date. They were
 * not checked against a live response (no key was available). primary_completion_date,
 * date_last_created and date_last_updated were not in that sample and are read only if present.
 *
 * Contact names, emails and phones of sites and central contacts, and the principal
 * investigator, are never imported and are removed from the kept raw record.
 */
final class NciCtsMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    /**
     * CTRP trial statuses (current_trial_status), mapped as CTRP maps them to ClinicalTrials.gov
     * (from prior knowledge of the CTRP documentation; unverified).
     */
    private const array STATUSES = [
        'IN REVIEW' => StudyStatus::NotYetRecruiting,
        'APPROVED' => StudyStatus::NotYetRecruiting,
        'ACTIVE' => StudyStatus::Recruiting,
        'ENROLLING BY INVITATION' => StudyStatus::EnrollingByInvitation,
        'TEMPORARILY CLOSED TO ACCRUAL' => StudyStatus::Suspended,
        'TEMPORARILY CLOSED TO ACCRUAL AND INTERVENTION' => StudyStatus::Suspended,
        'CLOSED TO ACCRUAL' => StudyStatus::ActiveNotRecruiting,
        'CLOSED TO ACCRUAL AND INTERVENTION' => StudyStatus::ActiveNotRecruiting,
        'COMPLETE' => StudyStatus::Completed,
        'COMPLETED' => StudyStatus::Completed,
        'ADMINISTRATIVELY COMPLETE' => StudyStatus::Terminated,
        'WITHDRAWN' => StudyStatus::Withdrawn,
    ];

    private const array PHASES = [
        'I' => 'PHASE1',
        'II' => 'PHASE2',
        'III' => 'PHASE3',
        'IV' => 'PHASE4',
        'I_II' => 'PHASE1, PHASE2',
        'II_III' => 'PHASE2, PHASE3',
        'NA' => 'NA',
    ];

    private const array STUDY_TYPES = [
        'INTERVENTIONAL' => 'INTERVENTIONAL',
        'NON-INTERVENTIONAL' => 'OBSERVATIONAL',
        'OBSERVATIONAL' => 'OBSERVATIONAL',
        'EXPANDED ACCESS' => 'EXPANDED_ACCESS',
    ];

    private const array OUTCOME_TYPES = ['PRIMARY' => 'PRIMARY', 'SECONDARY' => 'SECONDARY', 'OTHER_PRE_SPECIFIED' => 'OTHER', 'OTHER' => 'OTHER'];

    /** Keys holding personal data, dropped wherever they appear in the raw record. */
    private const array PERSONAL_KEYS = [
        'contact_name', 'contact_email', 'contact_phone', 'org_email', 'org_phone', 'org_fax', 'org_tty',
        'central_contact', 'principal_investigator',
    ];

    public function payloadId(array $payload): ?string
    {
        $id = $payload['nci_id'] ?? null;

        return is_string($id) && preg_match(NciCtsSource::ID_PATTERN, $id) === 1 ? $id : null;
    }

    public function map(array $payload): MappedStudy
    {
        $id = $this->payloadId($payload) ?? throw new InvalidStudyPayload('The NCI Clinical Trials Search record has no valid NCI id (nci_id).');

        $status = $this->status($payload['current_trial_status'] ?? null);
        $structured = $this->array($this->array($payload['eligibility'] ?? null)['structured'] ?? null);
        $enrollment = is_int($payload['minimum_target_accrual_number'] ?? null) && $payload['minimum_target_accrual_number'] > 0
            ? $payload['minimum_target_accrual_number']
            : null;

        $dates = [
            'start_date' => $this->date($payload['start_date'] ?? null),
            'primary_completion_date' => $this->date($payload['primary_completion_date'] ?? null),
            'completion_date' => $this->date($payload['completion_date'] ?? null),
        ];

        $hasResults = in_array(strtoupper((string) (is_scalar($payload['results_posted'] ?? null) ? $payload['results_posted'] : '')), ['1', 'YES', 'TRUE'], true);

        $attributes = [
            'brief_title' => $this->short($payload['brief_title'] ?? null) ?? $this->short($payload['official_title'] ?? null) ?? $id,
            'official_title' => $this->text($payload['official_title'] ?? null),
            'acronym' => $this->short($payload['acronym'] ?? null),
            'org_study_id' => $this->short($payload['protocol_id'] ?? null),
            'overall_status' => $status?->value,
            'status_verified_date' => $this->date($payload['record_verification_date'] ?? null),
            ...$dates,
            'study_first_post_date' => $this->date($payload['date_last_created'] ?? null),
            'last_update_post_date' => $this->date($payload['date_last_updated'] ?? null),
            'has_expanded_access' => false,
            'brief_summary' => $this->text($payload['brief_summary'] ?? null),
            'detailed_description' => $this->text($payload['detail_description'] ?? null),
            'study_type' => self::STUDY_TYPES[strtoupper(trim((string) $this->short($payload['study_protocol_type'] ?? null)))] ?? null,
            'phase' => $this->phase($payload['phase'] ?? null),
            'enrollment_count' => $enrollment,
            'enrollment_type' => $enrollment !== null ? 'ESTIMATED' : null,
            'sponsor_name' => $this->short($payload['lead_org'] ?? null),
            'sponsor_class' => null,
            'responsible_party_type' => null,
            'eligibility_criteria' => $this->eligibility($this->list($this->array($payload['eligibility'] ?? null)['unstructured'] ?? null)),
            'min_age' => $this->age($structured, 'min'),
            'max_age' => $this->age($structured, 'max'),
            'gender' => in_array($structured['sex'] ?? null, ['ALL', 'FEMALE', 'MALE'], true) ? $structured['sex'] : null,
            'is_fda_regulated_drug' => false,
            'is_fda_regulated_device' => false,
            'ipd_sharing' => null,
            'has_results' => $hasResults,
        ];

        $relations = $this->emptyRelations();
        $relations['secondaryIds'] = $this->secondaryIds($payload);
        $relations['collaborators'] = $this->collaborators($payload['collaborators'] ?? null);
        $relations['conditions'] = $this->conditions($this->list($payload['diseases'] ?? null));
        $relations['keywords'] = $this->keywords($payload['keywords'] ?? null);
        $relations['interventions'] = $this->interventions($this->list($payload['arms'] ?? null));
        $relations['armGroups'] = $this->armGroups($this->list($payload['arms'] ?? null));
        $relations['locations'] = $this->locations($this->list($payload['sites'] ?? null));
        $relations['outcomes'] = $this->outcomes($this->list($payload['outcome_measures'] ?? null));

        return new MappedStudy(
            source: NciCtsSource::KEY,
            sourceId: $id,
            attributes: $attributes,
            relations: $relations,
            facts: $this->facts($status?->value, $relations['locations'], $dates, $hasResults),
            raw: new RawDocument(
                'json',
                json_encode($this->withoutPersonalData($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
                NciCtsClient::BASE_URL.'/trials/'.$id,
            ),
        );
    }

    private function status(mixed $value): ?StudyStatus
    {
        return is_string($value) ? (self::STATUSES[strtoupper(trim($value))] ?? null) : null;
    }

    /** The phase object ({"phase": "I_II", ...}) or a bare phase code; "O" (other) has no equivalent. */
    private function phase(mixed $value): ?string
    {
        $code = is_array($value) ? ($value['phase'] ?? null) : $value;

        return is_string($code) ? (self::PHASES[strtoupper(trim($code))] ?? null) : null;
    }

    /**
     * "12 Years"; the CTRP placeholder of 999 years means no upper limit.
     *
     * @param  array<string, mixed>  $structured
     */
    private function age(array $structured, string $bound): ?string
    {
        $number = $structured["{$bound}_age_number"] ?? null;

        if (! is_int($number) && ! is_float($number)) {
            return null;
        }

        if ($bound === 'max' && $number >= 999) {
            return null;
        }

        $unit = $this->short($structured["{$bound}_age_unit"] ?? null) ?? 'Years';

        return ($number == (int) $number ? (string) (int) $number : (string) $number).' '.$unit;
    }

    /**
     * @param  list<array<string, mixed>>  $criteria
     */
    private function eligibility(array $criteria): ?string
    {
        usort($criteria, fn (array $a, array $b): int => ($a['display_order'] ?? 0) <=> ($b['display_order'] ?? 0));
        $sections = ['Inclusion criteria' => [], 'Exclusion criteria' => []];

        foreach ($criteria as $criterion) {
            $text = $this->text($criterion['description'] ?? null);

            if ($text !== null) {
                $sections[($criterion['inclusion_indicator'] ?? true) === false ? 'Exclusion criteria' : 'Inclusion criteria'][] = str_replace("\r\n", "\n", $text);
            }
        }

        $parts = [];

        foreach ($sections as $heading => $items) {
            if ($items !== []) {
                $parts[] = $heading.":\n".implode("\n", $items);
            }
        }

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * The NCT number and every registry identifier among the other ids. The NCI program ids
     * (CTEP, DCP, CCR) and the lead organization's protocol id are not registry identifiers.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array<string, string|null>>
     */
    private function secondaryIds(array $payload): array
    {
        $parser = new IdentifierParser;
        $pairs = [];

        $nct = is_string($payload['nct_id'] ?? null) ? $parser->parse($payload['nct_id']) : null;

        if ($nct !== null) {
            $pairs[] = [$nct->value, 'NCT'];
        }

        foreach ($this->list($payload['other_ids'] ?? null) as $other) {
            $value = $other['value'] ?? null;
            $identifier = is_string($value) ? $parser->parse($value, is_string($other['name'] ?? null) ? $other['name'] : null) : null;

            if ($identifier !== null) {
                $pairs[] = [$identifier->value, strtoupper($identifier->scheme->value)];
            }
        }

        return $this->secondaryIdRows($pairs);
    }

    /**
     * @return list<array<string, string|null>>
     */
    private function collaborators(mixed $collaborators): array
    {
        $rows = [];

        foreach (is_array($collaborators) ? $collaborators : [] as $collaborator) {
            $name = $this->short(is_array($collaborator) ? ($collaborator['name'] ?? null) : $collaborator);

            if ($name !== null) {
                $rows[$name] = ['name' => $name, 'class' => null];
            }
        }

        return array_values($rows);
    }

    /**
     * Diseases the trial itself lists (inclusion_indicator TRIAL); the API also returns their
     * NCI Thesaurus ancestors (TREE), which are not the trial's conditions.
     *
     * @param  list<array<string, mixed>>  $diseases
     * @return list<array<string, string>>
     */
    private function conditions(array $diseases): array
    {
        $rows = [];

        foreach ($diseases as $disease) {
            if (($disease['inclusion_indicator'] ?? 'TRIAL') !== 'TRIAL') {
                continue;
            }

            $name = $this->short($disease['preferred_name'] ?? null) ?? $this->short($disease['display_name'] ?? null);

            if ($name !== null) {
                $rows[$name] = ['condition' => $name];
            }
        }

        return array_values($rows);
    }

    /**
     * @return list<array<string, string>>
     */
    private function keywords(mixed $keywords): array
    {
        $rows = [];

        foreach (is_array($keywords) ? $keywords : [] as $keyword) {
            $keyword = $this->short($keyword);

            if ($keyword !== null) {
                $rows[$keyword] = ['keyword' => $keyword];
            }
        }

        return array_values($rows);
    }

    /**
     * Interventions of every arm, once each. The API's intervention description is the NCI
     * Thesaurus definition of the agent, not the trial's use of it, so it is not imported.
     *
     * @param  list<array<string, mixed>>  $arms
     * @return list<array<string, mixed>>
     */
    private function interventions(array $arms): array
    {
        $rows = [];

        foreach ($arms as $arm) {
            foreach ($this->list($arm['interventions'] ?? null) as $intervention) {
                $name = $this->short($intervention['intervention_name'] ?? $intervention['name'] ?? null);
                $type = $this->short($intervention['intervention_type'] ?? $intervention['type'] ?? null);

                if ($name !== null) {
                    $rows[$name] ??= [
                        'type' => $type !== null ? strtoupper(str_replace([' ', '-'], '_', $type)) : null,
                        'name' => $name,
                        'description' => null,
                    ];
                }
            }
        }

        return array_values($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $arms
     * @return list<array<string, string|null>>
     */
    private function armGroups(array $arms): array
    {
        $rows = [];

        foreach ($arms as $arm) {
            $label = $this->short($arm['arm_name'] ?? null);

            if ($label !== null) {
                $rows[$label] = [
                    'label' => $label,
                    'type' => $this->short($arm['arm_type'] ?? null),
                    'description' => $this->text($arm['arm_description'] ?? null),
                ];
            }
        }

        return array_values($rows);
    }

    /**
     * Sites (organization and address only). The site recruitment status is mapped like the
     * trial status (ACTIVE, CLOSED_TO_ACCRUAL...).
     *
     * @param  list<array<string, mixed>>  $sites
     * @return list<array<string, mixed>>
     */
    private function locations(array $sites): array
    {
        $rows = [];

        foreach ($sites as $site) {
            $coordinates = $this->array($site['org_coordinates'] ?? null);
            $status = is_string($site['recruitment_status'] ?? null) ? $this->status(str_replace('_', ' ', $site['recruitment_status'])) : null;

            $rows[] = [
                'facility' => $this->text($site['org_name'] ?? null),
                'city' => $this->short($site['org_city'] ?? null),
                'state' => $this->short($site['org_state_or_province'] ?? null),
                'country' => $this->short($site['org_country'] ?? null),
                'status' => $status?->value,
                'zip' => $this->short($site['org_postal_code'] ?? null),
                'latitude' => is_numeric($coordinates['lat'] ?? null) ? (float) $coordinates['lat'] : null,
                'longitude' => is_numeric($coordinates['lon'] ?? null) ? (float) $coordinates['lon'] : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $measures
     * @return list<array<string, string|null>>
     */
    private function outcomes(array $measures): array
    {
        $rows = [];

        foreach ($measures as $measure) {
            $name = $this->text($measure['name'] ?? null);

            if ($name !== null) {
                $rows[] = [
                    'type' => self::OUTCOME_TYPES[strtoupper((string) $this->short($measure['type_code'] ?? null))] ?? null,
                    'measure' => $name,
                    'description' => $this->text($measure['description'] ?? null),
                    'time_frame' => $this->text($measure['timeframe'] ?? null),
                ];
            }
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * @param  array<mixed>  $node
     * @return array<mixed>
     */
    private function withoutPersonalData(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, self::PERSONAL_KEYS, true)) {
                unset($node[$key]);
            } elseif (is_array($value)) {
                $node[$key] = $this->withoutPersonalData($value);
            }
        }

        return $node;
    }
}
