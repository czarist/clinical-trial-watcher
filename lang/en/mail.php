<?php

declare(strict_types=1);

// Emails sent to account holders, in the recipient's language.
return [
    'notice' => 'This information comes from the public ClinicalTrials.gov registry. It is not medical advice or a decision about eligibility; talk to a health professional and the study team.',

    'alert_matches' => [
        'subject' => ':count study update for your alert ":name"|:count study updates for your alert ":name"',
        'greeting' => 'Alert: :name',
        'intro' => ':count study update matches this alert.|:count study updates match this alert.',
        'more' => 'And :count more.',
        'action' => 'Open your alerts',
        'study_removed' => 'Study removed',
        'unknown' => 'unknown',
        'reason' => [
            'new_study' => 'New study',
            'updated_study' => 'Updated study',
            'status_change' => 'Status changed',
        ],
    ],

    'team_invitation' => [
        'subject' => 'Invitation to the team :team',
        'intro' => ':inviter invited you to the team ":team" on Clinical Trial Watcher.',
        'body' => "Members share the team's saved searches and AI digest reports. Nothing changes until you accept.",
        'action' => 'Review the invitation',
    ],
];
