<?php

declare(strict_types=1);

return [

    /*
    | New accounts get a free trial (Cashier "generic trial" on users.trial_ends_at).
    | During the trial the account has the limits of the trial plan.
    */
    'trial_days' => (int) env('TRIAL_DAYS', 14),

    'trial_plan' => env('BILLING_TRIAL_PLAN', 'Pro'),

    /*
    | Plan used when an account has no paid subscription and no trial.
    */
    'free_plan' => env('BILLING_FREE_PLAN', 'Basic'),

    /*
    | Cashier subscription type used for plan subscriptions.
    */
    'subscription_type' => 'default',

];
