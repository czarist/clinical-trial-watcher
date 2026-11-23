<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\User;
use Laravel\Cashier\Cashier;

/**
 * Stripe subscriptions through Laravel Cashier.
 */
final readonly class CashierSubscriptionGateway implements SubscriptionGateway
{
    public function __construct(private string $subscriptionType) {}

    #[\Override]
    public function checkoutUrl(User $user, Plan $plan, int $trialDays, string $successUrl, string $cancelUrl): string
    {
        $builder = $user->newSubscription($this->subscriptionType, (string) $plan->stripe_price_id);

        if ($trialDays > 0) {
            $builder->trialDays($trialDays);
        }

        return $builder->checkout([
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => ['plan_id' => (string) $plan->id],
        ])->asStripeCheckoutSession()->url;
    }

    #[\Override]
    public function swap(User $user, Plan $plan): void
    {
        $user->subscription($this->subscriptionType)?->swap((string) $plan->stripe_price_id);
    }

    #[\Override]
    public function cancel(User $user): void
    {
        $user->subscription($this->subscriptionType)?->cancel();
    }

    #[\Override]
    public function resume(User $user): void
    {
        $user->subscription($this->subscriptionType)?->resume();
    }

    #[\Override]
    public function portalUrl(User $user, string $returnUrl): string
    {
        return $user->billingPortalUrl($returnUrl);
    }

    #[\Override]
    public function price(string $priceId): StripePrice
    {
        $price = Cashier::stripe()->prices->retrieve($priceId);

        return new StripePrice(
            id: $price->id,
            unitAmount: $price->unit_amount,
            currency: $price->currency,
            interval: $price->recurring?->interval,
            intervalCount: (int) ($price->recurring?->interval_count ?? 1),
            active: (bool) $price->active,
        );
    }
}
