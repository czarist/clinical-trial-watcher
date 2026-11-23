<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Team;
use App\Models\User;

/**
 * Paid features. Everything else (search, study details, change history, basic alerts) is free.
 *
 * Access follows the effective plan, so it lasts through Cashier's grace period after a
 * cancellation and ends on the subscription's end date.
 */
final readonly class Entitlements
{
    public function __construct(private PlanResolver $plans) {}

    /**
     * AI digests for the user's own saved searches (Pro AI and Team AI).
     */
    public function hasPersonalAiDigests(User $user): bool
    {
        return $this->plans->effectivePlan($user)?->grantsAiDigests() ?? false;
    }

    /**
     * A team workspace with shared saved searches and digests (Team AI).
     */
    public function canOwnTeam(User $user): bool
    {
        return $this->plans->effectivePlan($user)?->grantsTeamWorkspace() ?? false;
    }

    /**
     * The team's workspace is open while its owner's Team AI subscription grants it.
     */
    public function teamIsActive(Team $team): bool
    {
        return $team->owner !== null && $this->canOwnTeam($team->owner);
    }
}
