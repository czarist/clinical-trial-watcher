<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\HealthCanada;

use App\Enums\StudyStatus;
use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Import\MappedStudy;
use App\Services\ClinicalTrials\Import\RawDocument;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\Registries\Sources\MapsRegistryRecords;

/**
 * Maps a Health Canada protocol, joined with its drug products, to the common study model.
 *
 * The database only lists drug and biologic trials authorized in Canada: every record is
 * interventional and recruits in Canada. It publishes no phase, sites, eligibility criteria,
 * update date or other registries' identifiers, so these stay empty and the records cannot
 * be linked to their registrations elsewhere. The protocol number is the sponsor's code and
 * is never used for linking.
 */
final class HealthCanadaMapper implements StudyPayloadMapper
{
    use MapsRegistryRecords;

    /**
     * status_id of the API's status list: 1 ONGOING, 2 CLOSED, 3 PENDING. ONGOING does not
     * say whether the trial still recruits, so it has no equivalent.
     */
    private const array STATUSES = [
        1 => null,
        2 => StudyStatus::Completed,
        3 => StudyStatus::NotYetRecruiting,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    #[\Override]
    public function payloadId(array $payload): ?string
    {
        $id = is_array($payload['protocol'] ?? null) ? ($payload['protocol']['protocol_id'] ?? null) : null;

        return is_int($id) || (is_string($id) && ctype_digit($id)) ? (string) $id : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidStudyPayload
     */
    #[\Override]
    public function map(array $payload): MappedStudy
    {
        $protocol = is_array($payload['protocol'] ?? null) ? $payload['protocol'] : [];
        $sourceId = $this->payloadId($payload);

        if ($sourceId === null || (int) $sourceId < 1) {
            throw new InvalidStudyPayload('The Health Canada protocol has no protocol_id.');
        }

        $title = $this->short($protocol['protocol_title'] ?? null);

        if ($title === null) {
            throw new InvalidStudyPayload("The Health Canada protocol {$sourceId} has no title.");
        }

        $drugProducts = array_values(array_filter(is_array($payload['drugProducts'] ?? null) ? $payload['drugProducts'] : [], 'is_array'));
        $manufacturers = $this->distinct(array_map(fn (array $row): ?string => $this->short($row['manufacturer_name'] ?? null), $drugProducts));
        $statusId = $protocol['status_id'] ?? null;
        $status = is_int($statusId) ? (self::STATUSES[$statusId] ?? null) : null;
        $startDate = $this->date($protocol['start_date'] ?? null);
        $completionDate = $this->date($protocol['end_date'] ?? null);
        $populations = $this->distinct(array_map(
            fn (mixed $row): ?string => is_array($row) ? $this->short($row['study_population'] ?? null) : null,
            is_array($protocol['studyPopulationList'] ?? null) ? $protocol['studyPopulationList'] : [],
        ));
        $locations = [['facility' => null, 'city' => null, 'state' => null, 'country' => 'Canada',
            'status' => null, 'zip' => null, 'latitude' => null, 'longitude' => null]];

        $relations = $this->emptyRelations();
        $relations['secondaryIds'] = $this->secondaryIdRows([[$protocol['submission_no'] ?? null, 'Health Canada submission number']]);
        $relations['conditions'] = array_map(fn (string $condition): array => ['condition' => $condition], $this->distinct(array_map(
            fn (mixed $row): ?string => is_array($row) ? $this->short($row['med_condition'] ?? null) : null,
            is_array($protocol['medConditionList'] ?? null) ? $protocol['medConditionList'] : [],
        )));
        $relations['interventions'] = array_map(fn (string $brand): array => ['type' => null, 'name' => $brand, 'description' => null], $this->distinct(
            array_map(fn (array $row): ?string => $this->short($row['brand_name'] ?? null), $drugProducts),
        ));
        $relations['collaborators'] = array_map(fn (string $name): array => ['name' => $name, 'class' => null], array_slice($manufacturers, 1));
        $relations['locations'] = $locations;

        return new MappedStudy(
            source: HealthCanadaSource::KEY,
            sourceId: $sourceId,
            attributes: [
                'brief_title' => $title,
                'official_title' => $title,
                'acronym' => null,
                'org_study_id' => $this->short($protocol['protocol_no'] ?? null),
                'overall_status' => $status?->value,
                'status_verified_date' => null,
                'start_date' => $startDate,
                'primary_completion_date' => null,
                'completion_date' => $completionDate,
                'study_first_post_date' => null,
                'last_update_post_date' => null,
                'has_expanded_access' => false,
                'brief_summary' => null,
                'detailed_description' => null,
                'study_type' => 'INTERVENTIONAL',
                'phase' => null,
                'enrollment_count' => null,
                'enrollment_type' => null,
                'sponsor_name' => $manufacturers[0] ?? null,
                'sponsor_class' => null,
                'responsible_party_type' => null,
                'eligibility_criteria' => $populations === [] ? null : 'Study population: '.implode(', ', $populations),
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
                json_encode(['protocol' => $protocol, 'drugProducts' => $drugProducts], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                is_string($payload['url'] ?? null) ? $payload['url'] : null,
            ),
        );
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
