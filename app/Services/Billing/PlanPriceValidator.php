<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Exceptions\BillingFailed;
use App\Models\Plan;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Checks that the Stripe price linked to a plan charges the plan's price, in the configured
 * currency and interval, so a misconfigured price is never sent to checkout.
 */
final readonly class PlanPriceValidator
{
    public function __construct(
        private SubscriptionGateway $gateway,
        private Config $config,
    ) {}

    /**
     * @return list<string> problems found; empty when the price matches
     */
    public function problems(Plan $plan): array
    {
        if ($plan->stripe_price_id === null) {
            return ['no Stripe price is configured'];
        }

        $price = $this->gateway->price($plan->stripe_price_id);
        $currency = (string) $this->config->get('billing.currency', 'usd');
        $interval = (string) $this->config->get('billing.interval', 'month');
        $problems = [];

        if (! $price->active) {
            $problems[] = 'the Stripe price is archived';
        }

        if (strtolower($price->currency) !== $currency || strtolower((string) $plan->currency) !== $currency) {
            $problems[] = "the currency is {$price->currency}, expected {$currency}";
        }

        if ($price->interval !== $interval || $price->intervalCount !== 1) {
            $problems[] = sprintf('the interval is %s, expected 1 %s', $price->interval === null ? 'one-time' : "{$price->intervalCount} {$price->interval}", $interval);
        }

        if ($price->unitAmount !== $plan->priceInCents()) {
            $problems[] = sprintf('the amount is %s cents, expected %d', $price->unitAmount ?? 'no fixed amount', $plan->priceInCents());
        }

        return $problems;
    }

    public function assertValid(Plan $plan): void
    {
        $problems = $this->problems($plan);

        if ($problems !== []) {
            report(new \RuntimeException("Stripe price for plan {$plan->name} is misconfigured: ".implode('; ', $problems)));

            throw BillingFailed::priceMisconfigured();
        }
    }
}
