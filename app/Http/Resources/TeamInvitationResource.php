<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TeamInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TeamInvitation
 */
final class TeamInvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'team' => $this->whenLoaded('team', fn (): array => [
                'id' => $this->team->id,
                'name' => $this->team->name,
                'owner_name' => $this->team->relationLoaded('owner') ? $this->team->owner?->name : null,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
