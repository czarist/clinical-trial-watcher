<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\BillingFailed;
use App\Models\Plan;
use App\Models\User;

/**
 * Subscription rules: checkout for a first paid plan, swap for upgrades and downgrades
 * between paid plans, and cancellation to return to the free plan.
 */
final readonly class BillingService
{
    public function __construct(
        private SubscriptionGateway $gateway,
        private PlanResolver $plans,
        private PlanSynchronizer $synchronizer,
        private string $frontendUrl,
    ) {}

    /**
     * @return string|null checkout URL when the user must pay; null when the change was applied directly
     */
    public function changePlan(User $user, Plan $plan): ?string
    {
        $subscription = $user->subscription($this->plans->subscriptionType());
        $hasSubscription = $subscription !== null && $subscription->valid();

        if ($plan->isFree()) {
            if ($hasSubscription && ! $subscription->canceled()) {
                $this->gateway->cancel($user);
            }

            return null;
        }

        if (! $plan->isPurchasable()) {
            throw BillingFailed::planNotPurchasable();
        }

        if (! $hasSubscription) {
            return $this->gateway->checkoutUrl(
                $user,
                $plan,
                $this->checkoutTrialDays($user, $plan),
                "{$this->frontendUrl}/account?checkout=success",
                "{$this->frontendUrl}/account?checkout=canceled",
            );
        }

        $this->gateway->swap($user, $plan);
        $this->synchronizer->sync($user->refresh());

        return null;
    }

    public function cancel(User $user): void
    {
        $subscription = $user->subscription($this->plans->subscriptionType());

        if ($subscription === null || ! $subscription->valid() || $subscription->canceled()) {
            throw BillingFailed::noSubscription();
        }

        $this->gateway->cancel($user);
    }

    public function resume(User $user): void
    {
        $subscription = $user->subscription($this->plans->subscriptionType());

        if ($subscription === null || ! $subscription->onGracePeriod()) {
            throw BillingFailed::notResumable();
        }

        $this->gateway->resume($user);
    }

    public function portalUrl(User $user): string
    {
        if ($user->stripe_id === null) {
            throw BillingFailed::noSubscription();
        }

        return $this->gateway->portalUrl($user, "{$this->frontendUrl}/account");
    }

    /**
     * The plan whose limits apply now and the state of the subscription.
     *
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $plan = $this->plans->effectivePlan($user);
        $subscription = $user->subscription($this->plans->subscriptionType());

        return [
            'effective_plan' => $plan !== null ? ['id' => $plan->id, 'name' => $plan->name] : null,
            'alerts_limit' => $plan?->alertsLimit() ?? 0,
            'alerts_count' => $user->alerts()->count(),
            'on_trial' => $user->onGenericTrial(),
            'trial_ends_at' => $user->trial_ends_at?->toIso8601String(),
            'subscription' => $subscription === null ? null : [
                'status' => $subscription->stripe_status,
                'plan_id' => $subscription->plan_id,
                'on_grace_period' => $subscription->onGracePeriod(),
                'ends_at' => $subscription->ends_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * A plan's trial is offered only to accounts that never subscribed and are not on the signup trial.
     */
    private function checkoutTrialDays(User $user, Plan $plan): int
    {
        if ($user->subscriptions()->exists() || $user->onGenericTrial()) {
            return 0;
        }

        return $plan->trial_days;
    }
}
