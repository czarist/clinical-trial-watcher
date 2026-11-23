<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

/**
 * A personal saved search belongs to its user. A team's shared saved search can be seen by
 * the team's members and changed only by the team owner.
 */
final class AlertPolicy
{
    public function view(User $user, Alert $alert): bool
    {
        if ($alert->team !== null) {
            return $alert->team->hasMember($user);
        }

        return $alert->user_id === $user->id;
    }

    public function update(User $user, Alert $alert): bool
    {
        if ($alert->team !== null) {
            return $alert->team->isOwnedBy($user);
        }

        return $alert->user_id === $user->id;
    }

    public function delete(User $user, Alert $alert): bool
    {
        return $this->update($user, $alert);
    }
}
