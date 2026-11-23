<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\TrialChangeType;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialChange;
use App\Models\ClinicalTrials\ClinicalTrialIdentifier;
use App\Services\Registries\SourceRegistry;
use Illuminate\Http\Request;

/**
 * Study detail with every related list.
 *
 * @mixin ClinicalTrial
 */
final class ClinicalTrialResource extends ClinicalTrialSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'official_title' => $this->official_title,
            'org_study_id' => $this->org_study_id,
            'brief_summary' => $this->brief_summary,
            'detailed_description' => $this->detailed_description,
            'status_verified_date' => $this->status_verified_date?->toDateString(),
            'completion_date' => $this->completion_date?->toDateString(),
            'study_first_post_date' => $this->study_first_post_date?->toDateString(),
            'sponsor_class' => $this->sponsor_class,
            'responsible_party_type' => $this->responsible_party_type,
            'enrollment' => ['count' => $this->enrollment_count, 'type' => $this->enrollment_type],
            'eligibility' => [
                'criteria' => $this->eligibility_criteria,
                'min_age' => $this->min_age,
                'max_age' => $this->max_age,
                'sex' => $this->gender,
            ],
            'oversight' => [
                'is_fda_regulated_drug' => $this->is_fda_regulated_drug,
                'is_fda_regulated_device' => $this->is_fda_regulated_device,
            ],
            'has_expanded_access' => $this->has_expanded_access,
            'ipd_sharing' => $this->ipd_sharing,
            'keywords' => $this->whenLoaded('keywords', fn () => $this->keywords->pluck('keyword')),
            'mesh_terms' => $this->whenLoaded('meshTerms', fn () => $this->meshTerms->map->only(['mesh_id', 'term', 'is_primary'])),
            'secondary_ids' => $this->whenLoaded('secondaryIds', fn () => $this->secondaryIds->map->only(['secondary_id', 'type', 'domain', 'link'])),
            'collaborators' => $this->whenLoaded('collaborators', fn () => $this->collaborators->map->only(['name', 'class'])),
            'locations' => $this->whenLoaded('locations', fn () => $this->locations->map->only(['facility', 'city', 'state', 'country', 'zip', 'latitude', 'longitude'])),
            'interventions' => $this->whenLoaded('interventions', fn () => $this->interventions->map->only(['type', 'name', 'description'])),
            'arm_groups' => $this->whenLoaded('armGroups', fn () => $this->armGroups->map->only(['label', 'type', 'description'])),
            'outcomes' => $this->whenLoaded('outcomes', fn () => $this->outcomes->map->only(['type', 'measure', 'description', 'time_frame'])),
            'references' => $this->whenLoaded('references', fn () => $this->references->map->only(['pmid', 'type', 'citation', 'url', 'pubmed_url'])),
            // Other registrations of the same study, and the record shown for it when this is one.
            'duplicate_of' => $this->whenLoaded('canonical', fn () => $this->canonical === null ? null : $this->registration($this->canonical)),
            'also_registered_as' => $this->whenLoaded('duplicates', fn () => $this->duplicates->map(fn (ClinicalTrial $trial): array => $this->registration($trial))->values()),
            'identifiers' => $this->whenLoaded('identifiers', fn () => $this->identifiers->map(fn (ClinicalTrialIdentifier $identifier): array => [
                'scheme' => $identifier->scheme->value,
                'value' => $identifier->value,
                'is_primary' => $identifier->is_primary,
            ])->values()),
            'status_history' => $this->whenLoaded('trialChanges', fn () => $this->trialChanges
                ->filter(fn (ClinicalTrialChange $change): bool => $change->type === TrialChangeType::StatusChanged)
                ->values()
                ->map(fn (ClinicalTrialChange $change): array => [
                    'previous_status' => $change->previous_status,
                    'new_status' => $change->new_status,
                    'detected_at' => $change->created_at?->toIso8601String(),
                ])),
        ];
    }

    /**
     * @return array{source: string, source_id: string, registry: string, registry_url: ?string, nct_id: ?string}
     */
    private function registration(ClinicalTrial $trial): array
    {
        $sources = app(SourceRegistry::class);

        return [
            'source' => $trial->source,
            'source_id' => $trial->source_id,
            'registry' => $sources->name($trial->source),
            'registry_url' => $sources->urlFor($trial),
            'nct_id' => $trial->nct_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => ['disclaimer' => self::DISCLAIMER]];
    }
}
