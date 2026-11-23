<?php

declare(strict_types=1);

// Error messages returned by the API.
return [
    'unauthenticated' => 'Please log in to continue.',
    'forbidden' => 'You do not have permission to perform this action.',
    'not_found' => 'The requested item was not found.',
    'throttled' => 'Too many requests. Please wait a moment and try again.',

    'alert_limit' => 'You can keep up to :limit saved searches. Delete one to create another.',

    'billing' => [
        'plan_not_purchasable' => 'This plan cannot be purchased online.',
        'no_subscription' => 'You do not have an active subscription.',
        'already_subscribed' => 'You already have a subscription; change its plan instead.',
        'price_misconfigured' => 'This plan is temporarily unavailable for purchase. Please try again later.',
        'not_resumable' => 'The subscription is not in its cancellation grace period.',
    ],

    'paid' => [
        'ai_digests' => 'AI digests are part of the Pro AI and Team AI plans. Basic alerts stay free.',
        'team_workspace' => "This team's workspace needs an active Team AI subscription held by the team owner.",
    ],

    'team' => [
        'already_owns_team' => 'You already own a team. A Team AI subscription covers one team.',
        'already_member' => 'This person is already a member of the team.',
        'owner_cannot_leave' => 'The team owner cannot leave the team. Delete the team instead.',
        'invitation_not_found' => 'Invitation not found.',
    ],
];
