<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AlertMatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AlertMatch
 */
final class AlertMatchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reason' => $this->reason->value,
            'previous_status' => $this->change?->previous_status,
            'new_status' => $this->change?->new_status,
            'notified_at' => $this->notified_at?->toIso8601String(),
            'matched_at' => $this->created_at?->toIso8601String(),
            'trial' => new ClinicalTrialSummaryResource($this->whenLoaded('trial')),
        ];
    }
}
