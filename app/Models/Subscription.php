<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Stripe subscription managed by Laravel Cashier, linked to one of the application's plans.
 */
class Subscription extends CashierSubscription
{
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
