<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Team;
use App\Models\User;

/**
 * Members (who accepted an invitation) can see a team; only its owner manages it.
 */
final class TeamPolicy
{
    public function view(User $user, Team $team): bool
    {
        return $team->hasMember($user);
    }

    public function manage(User $user, Team $team): bool
    {
        return $team->isOwnedBy($user);
    }
}
