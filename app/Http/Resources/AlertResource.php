<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Alert;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Alert
 */
final class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'name' => $this->name,
            'criteria' => (object) ($this->criteria ?? []),
            'frequency' => $this->frequency->value,
            'notify_status_changes' => $this->notify_status_changes,
            'is_active' => $this->is_active,
            'ai_digest' => [
                'enabled' => $this->ai_digest_enabled,
                'frequency' => $this->ai_digest_frequency->value,
                'language' => $this->ai_digest_language->value,
            ],
            'pending_matches_count' => $this->whenCounted('pendingMatches'),
            'last_notified_at' => $this->last_notified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
