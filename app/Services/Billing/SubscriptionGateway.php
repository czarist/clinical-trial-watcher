<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\User;

/**
 * Operations on the payment provider's subscription for a user.
 */
interface SubscriptionGateway
{
    /**
     * Starts a hosted checkout for the plan and returns the URL to redirect the user to.
     */
    public function checkoutUrl(User $user, Plan $plan, int $trialDays, string $successUrl, string $cancelUrl): string;

    /**
     * Moves an existing subscription to another plan (upgrade or downgrade), with proration.
     */
    public function swap(User $user, Plan $plan): void;

    /**
     * Cancels at the end of the current billing period.
     */
    public function cancel(User $user): void;

    public function resume(User $user): void;

    /**
     * URL of the provider's customer portal (payment methods, invoices).
     */
    public function portalUrl(User $user, string $returnUrl): string;

    /**
     * Reads a price from the provider to check its amount, currency and interval.
     */
    public function price(string $priceId): StripePrice;
}
