<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Decides which plan applies to a user: the plan of a valid subscription (active, trialing
 * or in its cancellation grace period, as Cashier defines it), otherwise the free plan.
 *
 * A subscription on a legacy plan keeps that plan, which grants no AI features.
 */
final readonly class PlanResolver
{
    public function __construct(private Config $config) {}

    public function effectivePlan(User $user): ?Plan
    {
        $subscription = $user->subscription($this->subscriptionType());

        if ($subscription !== null && $subscription->valid() && $subscription->plan !== null) {
            return $subscription->plan;
        }

        return $this->freePlan();
    }

    public function freePlan(): ?Plan
    {
        return Plan::query()->where('name', (string) $this->config->get('billing.free_plan'))->first();
    }

    public function subscriptionType(): string
    {
        return (string) $this->config->get('billing.subscription_type', 'default');
    }

    /**
     * Saved searches an account or team can create, the same on every plan.
     */
    public function savedSearchLimit(): int
    {
        return (int) $this->config->get('billing.saved_search_limit', 100);
    }
}
