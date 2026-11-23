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
        return new self('AI digests are part of the Pro AI and Team AI plans. Basic alerts stay free.');
    }

    public static function teamWorkspace(): self
    {
        return new self("This team's workspace needs an active Team AI subscription held by the team owner.");
    }

    #[\Override]
    public function status(): int
    {
        return 402;
    }
}
