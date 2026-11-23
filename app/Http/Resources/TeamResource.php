<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Team;
use App\Models\User;
use App\Services\Billing\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A team as seen by one of its members. Email addresses of members and pending invitations
 * are shown only to the owner.
 *
 * @mixin Team
 */
final class TeamResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $isOwner = $viewer !== null && $this->isOwnedBy($viewer);
        $membership = $viewer !== null ? $this->membershipOf($viewer) : null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'owner' => ['id' => $this->owner?->id, 'name' => $this->owner?->name],
            'is_owner' => $isOwner,
            'is_active' => app(Entitlements::class)->teamIsActive($this->resource),
            'receives_digests' => $membership?->receives_digests,
            'members' => $this->whenLoaded('members', fn (): array => $this->members->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $isOwner ? $member->email : null,
                'role' => $member->pivot->role instanceof \BackedEnum ? $member->pivot->role->value : $member->pivot->role,
            ])->values()->all()),
            'invitations' => $this->when(
                $isOwner && $this->relationLoaded('invitations'),
                fn () => TeamInvitationResource::collection($this->invitations),
            ),
        ];
    }
}
