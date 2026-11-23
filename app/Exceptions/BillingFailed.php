<?php

declare(strict_types=1);

namespace App\Exceptions;

final class BillingFailed extends ApplicationException
{
    public static function planNotPurchasable(): self
    {
        return new self('This plan cannot be purchased online.');
    }

    public static function noSubscription(): self
    {
        return new self('You do not have an active subscription.');
    }

    public static function alreadySubscribed(): self
    {
        return new self('You already have a subscription; change its plan instead.');
    }

    public static function priceMisconfigured(): self
    {
        return new self('This plan is temporarily unavailable for purchase. Please try again later.');
    }

    public static function notResumable(): self
    {
        return new self('The subscription is not in its cancellation grace period.');
    }

    #[\Override]
    public function status(): int
    {
        return 422;
    }
}
