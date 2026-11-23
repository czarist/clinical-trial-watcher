<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Alert;
use App\Models\User;

/**
 * Users can only see and change their own alerts.
 */
final class AlertPolicy
{
    public function view(User $user, Alert $alert): bool
    {
        return $alert->user_id === $user->id;
    }

    public function update(User $user, Alert $alert): bool
    {
        return $alert->user_id === $user->id;
    }

    public function delete(User $user, Alert $alert): bool
    {
        return $alert->user_id === $user->id;
    }
}
