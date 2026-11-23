<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * The action needs a paid plan the account (or the team's owner) does not have.
 */
final class PaidFeatureRequired extends ApplicationException
{
    public static function aiDigests(): self
    {
        return new self(__('errors.paid.ai_digests'));
    }

    public static function teamWorkspace(): self
    {
        return new self(__('errors.paid.team_workspace'));
    }

    #[\Override]
    public function status(): int
    {
        return 402;
    }
}
