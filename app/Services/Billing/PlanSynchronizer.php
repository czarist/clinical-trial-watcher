<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\User;

/**
 * Keeps users.plan_id and subscriptions.plan_id consistent with the Stripe subscription
 * that Cashier stores from webhooks.
 */
final readonly class PlanSynchronizer
{
    public function __construct(private PlanResolver $plans) {}

    public function sync(User $user): void
    {
        $subscription = $user->subscription($this->plans->subscriptionType());
        $plan = null;

        if ($subscription !== null) {
            $plan = Plan::query()->where('stripe_price_id', $subscription->stripe_price)->first();
            $subscription->forceFill(['plan_id' => $plan?->id])->save();
        }

        $active = $subscription !== null && $subscription->valid();

        $user->forceFill([
            'plan_id' => $active && $plan !== null ? $plan->id : $this->plans->freePlan()?->id,
            'subscription_ends_at' => $subscription?->ends_at,
        ])->save();
    }
}
