<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\Registries\SourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ClinicalTrial
 */
class ClinicalTrialSummaryResource extends JsonResource
{
    public const string DISCLAIMER = 'Registry data from ClinicalTrials.gov. It is not medical advice, a treatment recommendation or an eligibility decision.';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $sources = app(SourceRegistry::class);

        return [
            'nct_id' => $this->nct_id,
            'source' => $this->source,
            'source_id' => $this->source_id,
            'registry' => $sources->name((string) $this->source),
            'brief_title' => $this->brief_title,
            'acronym' => $this->acronym,
            'overall_status' => $this->overall_status,
            'phases' => $this->phases(),
            'study_type' => $this->study_type,
            'sponsor_name' => $this->sponsor_name,
            'start_date' => $this->start_date?->toDateString(),
            'primary_completion_date' => $this->primary_completion_date?->toDateString(),
            'last_update_post_date' => $this->last_update_post_date?->toDateString(),
            'has_results' => $this->has_results,
            'conditions' => $this->whenLoaded('conditions', fn () => $this->conditions->pluck('condition')),
            'locations_count' => $this->whenCounted('locations'),
            'registry_url' => $sources->recordUrl((string) $this->source, $this->source_id),
        ];
    }
}
