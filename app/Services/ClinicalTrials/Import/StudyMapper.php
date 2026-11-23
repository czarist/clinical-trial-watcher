<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

use App\Exceptions\InvalidStudyPayload;
use App\Services\ClinicalTrials\Changes\TrackedFacts;
use App\Services\Registries\ClinicalTrialsGovSource;
use Illuminate\Support\Arr;

/**
 * Maps a ClinicalTrials.gov API v2 study payload to clinical_trials columns and related rows.
 *
 * Every related list is always returned, empty when the payload has none, so the import
 * replaces obsolete rows. Rows missing a required value are skipped; the full payload is
 * kept in raw_json.
 */
final class StudyMapper implements StudyPayloadMapper
{
    /** Maximum length of indexed string columns. */
    private const int SHORT_TEXT = 255;

    /**
     * @param  array<string, mixed>  $payload
     */
    #[\Override]
    public function payloadId(array $payload): ?string
    {
        $nctId = Arr::get($payload, 'protocolSection.identificationModule.nctId');

        return is_string($nctId) ? $nctId : null;
    }

    /**
     * @param  array<string, mixed>  $study
     *
     * @throws InvalidStudyPayload
     */
    #[\Override]
    public function map(array $study): MappedStudy
    {
        $protocol = $this->section($study, 'protocolSection');
        $nctId = Arr::get($protocol, 'identificationModule.nctId');

        if (! is_string($nctId) || $nctId === '') {
            throw InvalidStudyPayload::missingNctId();
        }

        return new MappedStudy(
            source: ClinicalTrialsGovSource::KEY,
            sourceId: $nctId,
            nctId: $nctId,
            attributes: $this->attributes($study, $protocol),
            relations: [
                'secondaryIds' => $this->secondaryIds($protocol),
                'collaborators' => $this->collaborators($protocol),
                'references' => $this->references($protocol),
                'meshTerms' => $this->meshTerms($study),
                'conditions' => $this->strings($protocol, 'conditionsModule.conditions', 'condition'),
                'keywords' => $this->strings($protocol, 'conditionsModule.keywords', 'keyword'),
                'locations' => $this->locations($protocol),
                'interventions' => $this->interventions($protocol),
                'armGroups' => $this->armGroups($protocol),
                'outcomes' => $this->outcomes($protocol),
            ],
            facts: TrackedFacts::fromStudy($study),
        );
    }

    /**
     * @param  array<string, mixed>  $study
     * @param  array<string, mixed>  $protocol
     * @return array<string, mixed>
     */
    private function attributes(array $study, array $protocol): array
    {
        $phases = Arr::get($protocol, 'designModule.phases', []);

        return [
            'brief_title' => Arr::get($protocol, 'identificationModule.briefTitle'),
            'official_title' => Arr::get($protocol, 'identificationModule.officialTitle'),
            'acronym' => Arr::get($protocol, 'identificationModule.acronym'),
            'org_study_id' => Arr::get($protocol, 'identificationModule.orgStudyIdInfo.id'),
            'overall_status' => Arr::get($protocol, 'statusModule.overallStatus'),
            'status_verified_date' => $this->date($protocol, 'statusModule.statusVerifiedDate'),
            'start_date' => $this->date($protocol, 'statusModule.startDateStruct.date'),
            'primary_completion_date' => $this->date($protocol, 'statusModule.primaryCompletionDateStruct.date'),
            'completion_date' => $this->date($protocol, 'statusModule.completionDateStruct.date'),
            'study_first_post_date' => $this->date($protocol, 'statusModule.studyFirstPostDateStruct.date'),
            'last_update_post_date' => $this->date($protocol, 'statusModule.lastUpdatePostDateStruct.date'),
            'has_expanded_access' => (bool) Arr::get($protocol, 'statusModule.expandedAccessInfo.hasExpandedAccess', false),
            'brief_summary' => Arr::get($protocol, 'descriptionModule.briefSummary'),
            'detailed_description' => Arr::get($protocol, 'descriptionModule.detailedDescription'),
            'study_type' => Arr::get($protocol, 'designModule.studyType'),
            'phase' => is_array($phases) && $phases !== [] ? implode(', ', $phases) : null,
            'enrollment_count' => Arr::get($protocol, 'designModule.enrollmentInfo.count'),
            'enrollment_type' => Arr::get($protocol, 'designModule.enrollmentInfo.type'),
            'sponsor_name' => $this->short(Arr::get($protocol, 'sponsorCollaboratorsModule.leadSponsor.name')),
            'sponsor_class' => Arr::get($protocol, 'sponsorCollaboratorsModule.leadSponsor.class'),
            'responsible_party_type' => Arr::get($protocol, 'sponsorCollaboratorsModule.responsibleParty.type'),
            'eligibility_criteria' => Arr::get($protocol, 'eligibilityModule.eligibilityCriteria'),
            'min_age' => Arr::get($protocol, 'eligibilityModule.minimumAge'),
            'max_age' => Arr::get($protocol, 'eligibilityModule.maximumAge'),
            'gender' => Arr::get($protocol, 'eligibilityModule.sex'),
            'is_fda_regulated_drug' => (bool) Arr::get($protocol, 'oversightModule.isFdaRegulatedDrug', false),
            'is_fda_regulated_device' => (bool) Arr::get($protocol, 'oversightModule.isFdaRegulatedDevice', false),
            'ipd_sharing' => Arr::get($protocol, 'ipdSharingStatementModule.ipdSharing'),
            'has_results' => (bool) ($study['hasResults'] ?? false),
            'raw_json' => $study,
        ];
    }

    /**
     * @param  array<string, mixed>  $protocol
     * @return list<array<string, mixed>>
     */
    private function secondaryIds(array $protocol): array
    {
        return collect($this->list($protocol, 'identificationModule.secondaryIdInfos'))
            ->filter(fn (array $info): bool => $this->filled($info['id'] ?? null))
            ->unique('id')
            ->map(fn (array $info): array => [
                'secondary_id' => $this->short($info['id']),
                'type' => $info['type'] ?? null,
                'domain' => $this->short($info['domain'] ?? null),
                'link' => $this->short($info['link'] ?? null),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $protocol
     * @return list<array<string, mixed>>
     */
    private function collaborators(array $protocol): array
    {
        return $this->rows(
            $this->list($protocol, 'sponsorCollaboratorsModule.collaborators'),
            'name',
            fn (array $item): array => [
                'name' => $this->short($item['name']),
                'class' => $item['class'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $protocol
     * @return list<array<string, mixed>>
     */
    private function references(array $protocol): array
    {
        return array_map(
            fn (array $item): array => [
                'pmid' => $item['pmid'] ?? null,
                'type' => $item['type'] ?? null,
                'citation' => $item['citation'] ?? null,
                'url' => $item['url'] ?? null,
            ],
            $this->list($protocol, 'referencesModule.references'),
        );
    }

    /**
     * @param  array<string, mixed>  $study
     * @return list<array<string, mixed>>
     */
    private function meshTerms(array $study): array
    {
        $browse = $this->section($study, 'derivedSection');
        $toRow = fn (bool $primary): \Closure => fn (array $mesh): array => [
            'mesh_id' => $mesh['id'] ?? null,
            'term' => $this->short($mesh['term']),
            'is_primary' => $primary,
        ];

        return [
            ...$this->rows($this->list($browse, 'conditionBrowseModule.meshes'), 'term', $toRow(true)),
            ...$this->rows($this->list($browse, 'conditionBrowseModule.ancestors'), 'term', $toRow(false)),
        ];
    }

    /**
     * @param  array<string, mixed>  $protocol
     * @return list<array<string, mixed>>
     */
    private function locations(array $protocol): array
    {
        return array_map(
            fn (array $location): array => [
                'facility' => $location['facility'] ?? null,
                'city' => $this->short($location['city'] ?? null),
                'state' => $this->short($location['state'] ?? null),
                'country' => $this->short($location['country'] ?? null),
                'status' => $this->short($location['status'] ?? null),
                'zip' => $this->short($location['zip'] ?? null),
                'latitude' => $location['geoPoint']['lat'] ?? null,
                'longitude' => $location['geoPoint']['lon'] ?? null,
            ],
            $this->list($protocol, 'contactsLocationsModule.locations'),
        );
    }

    /**
     * @param  array<string, mixed>  $protocol
     * @return list<array<string, mixed>>
     */
    private function interventions(array $protocol): array
    {
        return $this->rows(
            $this->list($protocol, 'armsInterventionsModule.interventions'),
            'name',
            fn (array $item): array => [
                'type' => $item['type'] ?? null,
                'name' => $item['name'],
                'description' => $item['description'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $protocol
     * @return list<array<string, mixed>>
     */
    private function armGroups(array $protocol): array
    {
        return $this->rows(
            $this->list($protocol, 'armsInterventionsModule.armGroups'),
            'label',
            fn (array $item): array => [
                'label' => $item['label'],
                'type' => $item['type'] ?? null,
                'description' => $item['description'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $protocol
     * @return list<array<string, mixed>>
     */
    private function outcomes(array $protocol): array
    {
        $rows = [];

        foreach (['PRIMARY' => 'primaryOutcomes', 'SECONDARY' => 'secondaryOutcomes', 'OTHER' => 'otherOutcomes'] as $type => $key) {
            $rows = [...$rows, ...$this->rows(
                $this->list($protocol, "outcomesModule.{$key}"),
                'measure',
                fn (array $item): array => [
                    'type' => $type,
                    'measure' => $item['measure'],
                    'description' => $item['description'] ?? null,
                    'time_frame' => $item['timeFrame'] ?? null,
                ],
            )];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $protocol
     * @return list<array<string, string>>
     */
    private function strings(array $protocol, string $path, string $column): array
    {
        return collect($this->list($protocol, $path, allowScalars: true))
            ->filter(fn (mixed $value): bool => $this->filled($value))
            ->map(fn (string $value): array => [$column => $this->short($value)])
            ->unique($column)
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  \Closure(array<string, mixed>): array<string, mixed>  $toRow
     * @return list<array<string, mixed>>
     */
    private function rows(array $items, string $requiredKey, \Closure $toRow): array
    {
        return array_values(array_map(
            $toRow,
            array_filter($items, fn (array $item): bool => $this->filled($item[$requiredKey] ?? null)),
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    private function list(array $data, string $path, bool $allowScalars = false): array
    {
        $value = Arr::get($data, $path);

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => $allowScalars ? is_string($item) : is_array($item),
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function section(array $data, string $key): array
    {
        return is_array($data[$key] ?? null) ? $data[$key] : [];
    }

    /**
     * @param  array<string, mixed>  $protocol
     */
    private function date(array $protocol, string $path): ?string
    {
        return RegistryDate::normalize(Arr::get($protocol, $path));
    }

    private function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function short(mixed $value): ?string
    {
        return is_string($value) ? mb_substr($value, 0, self::SHORT_TEXT) : null;
    }
}
