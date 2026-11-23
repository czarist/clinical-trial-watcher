<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Decides which plan's limits apply to a user: an active subscription first,
 * then the free trial, then the plan stored on the account, then the free plan.
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

        if ($user->onGenericTrial()) {
            return $this->planNamed((string) $this->config->get('billing.trial_plan')) ?? $user->plan;
        }

        return $user->plan ?? $this->freePlan();
    }

    public function freePlan(): ?Plan
    {
        return $this->planNamed((string) $this->config->get('billing.free_plan'));
    }

    public function subscriptionType(): string
    {
        return (string) $this->config->get('billing.subscription_type', 'default');
    }

    private function planNamed(string $name): ?Plan
    {
        return Plan::query()->where('name', $name)->first();
    }
}
