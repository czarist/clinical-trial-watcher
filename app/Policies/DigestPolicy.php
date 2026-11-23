<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Digest;
use App\Models\User;
use App\Services\Billing\Entitlements;

/**
 * A personal digest is visible to its recipient. A team digest is visible to the team's
 * current members while the team's Team AI subscription is active.
 */
final readonly class DigestPolicy
{
    public function __construct(private Entitlements $entitlements) {}

    public function view(User $user, Digest $digest): bool
    {
        if ($digest->team_id !== null) {
            $team = $digest->team;

            return $team !== null && $team->hasMember($user) && $this->entitlements->teamIsActive($team);
        }

        return $digest->user_id === $user->id;
    }
}
