<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * The parts of a Stripe Price that must agree with the plan being sold.
 */
final readonly class StripePrice
{
    public function __construct(
        public string $id,
        public ?int $unitAmount,
        public string $currency,
        public ?string $interval,
        public int $intervalCount,
        public bool $active,
    ) {}
}
