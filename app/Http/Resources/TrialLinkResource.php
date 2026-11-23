<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ClinicalTrials\ClinicalTrialLink;
use App\Services\Registries\SourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Two records that share a registry identifier, for review by an operator.
 *
 * @mixin ClinicalTrialLink
 */
final class TrialLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'shared_identifier' => ['scheme' => $this->scheme->value, 'value' => $this->value],
            'records' => [$this->record($this->trial), $this->record($this->linkedTrial)],
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'reviewed_by' => $this->whenLoaded('reviewer', fn () => $this->reviewer?->name),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function record(?ClinicalTrial $trial): ?array
    {
        if ($trial === null) {
            return null;
        }

        $sources = app(SourceRegistry::class);

        return [
            'id' => $trial->id,
            'source' => $trial->source,
            'source_id' => $trial->source_id,
            'registry' => $sources->name($trial->source),
            'registry_url' => $sources->recordUrl($trial->source, $trial->source_id),
            'brief_title' => $trial->brief_title,
            'overall_status' => $trial->overall_status,
            'is_shown' => $trial->canonical_trial_id === null,
        ];
    }
}
