<?php

declare(strict_types=1);

namespace App\Exceptions;

final class BillingFailed extends ApplicationException
{
    public static function planNotPurchasable(): self
    {
        return new self(__('errors.billing.plan_not_purchasable'));
    }

    public static function noSubscription(): self
    {
        return new self(__('errors.billing.no_subscription'));
    }

    public static function alreadySubscribed(): self
    {
        return new self(__('errors.billing.already_subscribed'));
    }

    public static function priceMisconfigured(): self
    {
        return new self(__('errors.billing.price_misconfigured'));
    }

    public static function notResumable(): self
    {
        return new self(__('errors.billing.not_resumable'));
    }

    #[\Override]
    public function status(): int
    {
        return 422;
    }
}
