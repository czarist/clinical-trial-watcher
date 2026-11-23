<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\StripePrice;
use App\Services\Billing\SubscriptionGateway;

/**
 * Records calls instead of contacting Stripe, and applies them to the local subscription.
 *
 * Prices default to a monthly USD price with the amount of the plan that uses them;
 * set $prices to simulate a misconfigured price.
 */
final class FakeSubscriptionGateway implements SubscriptionGateway
{
    /** @var list<array{0: string, 1: int|null, 2: int|null}> */
    public array $calls = [];

    /** @var array<string, StripePrice> */
    public array $prices = [];

    #[\Override]
    public function checkoutUrl(User $user, Plan $plan, int $trialDays, string $successUrl, string $cancelUrl): string
    {
        $this->calls[] = ['checkout', $plan->id, $trialDays];

        return 'https://checkout.stripe.test/session/'.$plan->id;
    }

    #[\Override]
    public function swap(User $user, Plan $plan): void
    {
        $this->calls[] = ['swap', $plan->id, null];
        $user->subscription()?->forceFill(['stripe_price' => $plan->stripe_price_id])->save();
    }

    #[\Override]
    public function cancel(User $user): void
    {
        $this->calls[] = ['cancel', null, null];
        $user->subscription()?->forceFill(['ends_at' => now()->addMonth()])->save();
    }

    #[\Override]
    public function resume(User $user): void
    {
        $this->calls[] = ['resume', null, null];
        $user->subscription()?->forceFill(['ends_at' => null])->save();
    }

    #[\Override]
    public function portalUrl(User $user, string $returnUrl): string
    {
        $this->calls[] = ['portal', null, null];

        return 'https://billing.stripe.test/portal';
    }

    #[\Override]
    public function price(string $priceId): StripePrice
    {
        if (isset($this->prices[$priceId])) {
            return $this->prices[$priceId];
        }

        $plan = Plan::query()->where('stripe_price_id', $priceId)->first();

        return new StripePrice($priceId, $plan?->priceInCents(), 'usd', 'month', 1, true);
    }
}
