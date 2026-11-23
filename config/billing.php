<?php

declare(strict_types=1);

return [

    /*
    | Plan of every account without a valid paid subscription. Search, study details,
    | change history and basic alerts are free.
    */
    'free_plan' => env('BILLING_FREE_PLAN', 'Free'),

    /*
    | Saved searches (alerts) an account or a team can create, the same on every plan.
    | Saved searches above the limit are kept; only new ones are refused.
    */
    'saved_search_limit' => (int) env('SAVED_SEARCH_LIMIT', 100),

    /*
    | Every paid plan is a recurring price in this currency and interval.
    */
    'currency' => 'usd',

    'interval' => 'month',

    /*
    | Paid plans offered at checkout, keyed by plan name. The Stripe Price IDs come from the
    | environment; `php artisan billing:check-prices` copies them to plans.stripe_price_id and
    | checks that Stripe charges the listed amount, currency and interval.
    */
    'plans' => [
        'Pro AI' => [
            'price' => 19.00,
            'stripe_price' => env('STRIPE_PRICE_PRO_AI'),
        ],
        'Team AI' => [
            'price' => 99.00,
            'stripe_price' => env('STRIPE_PRICE_TEAM_AI'),
        ],
    ],

    /*
    | Cashier subscription type used for plan subscriptions.
    */
    'subscription_type' => 'default',

];
