<?php

declare(strict_types=1);

namespace Tests\Fakes;

use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\SubscriptionGateway;

/**
 * Records calls instead of contacting Stripe, and applies them to the local subscription.
 */
final class FakeSubscriptionGateway implements SubscriptionGateway
{
    /** @var list<array{0: string, 1: int|null, 2: int|null}> */
    public array $calls = [];

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
}
