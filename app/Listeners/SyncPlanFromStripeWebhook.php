<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Services\Billing\PlanSynchronizer;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * After Cashier stores a subscription change from a Stripe webhook, applies the matching plan.
 */
final readonly class SyncPlanFromStripeWebhook
{
    private const array SUBSCRIPTION_EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
    ];

    public function __construct(private PlanSynchronizer $synchronizer) {}

    public function handle(WebhookHandled $event): void
    {
        if (! in_array($event->payload['type'] ?? null, self::SUBSCRIPTION_EVENTS, true)) {
            return;
        }

        $customerId = $event->payload['data']['object']['customer'] ?? null;
        $user = is_string($customerId) ? Cashier::findBillable($customerId) : null;

        if ($user instanceof User) {
            $this->synchronizer->sync($user);
        }
    }
}
