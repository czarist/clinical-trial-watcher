<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;

final class AccountService
{
    /**
     * Updates the profile. A new email address must be verified again.
     */
    public function updateProfile(User $user, string $name, string $email): User
    {
        $emailChanged = $user->email !== $email;

        $user->forceFill([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => $emailChanged ? null : $user->email_verified_at,
        ])->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $user;
    }

    public function updatePassword(User $user, string $password): void
    {
        $user->forceFill(['password' => $password])->save();
    }

    /**
     * Marks the email as verified when the signed link's hash matches the current address.
     */
    public function verifyEmail(User $user, string $hash): bool
    {
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return false;
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return true;
    }
}
