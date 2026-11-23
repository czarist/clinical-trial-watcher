<?php

declare(strict_types=1);

// Wording of digests in English. Factual registry language only: no eligibility, treatment
// or efficacy statements.
return [
    'status' => [
        'ACTIVE_NOT_RECRUITING' => 'Active, not recruiting',
        'COMPLETED' => 'Completed',
        'ENROLLING_BY_INVITATION' => 'Enrolling by invitation',
        'NOT_YET_RECRUITING' => 'Not yet recruiting',
        'RECRUITING' => 'Recruiting',
        'SUSPENDED' => 'Suspended',
        'TERMINATED' => 'Terminated',
        'WITHDRAWN' => 'Withdrawn',
        'AVAILABLE' => 'Available',
        'NO_LONGER_AVAILABLE' => 'No longer available',
        'TEMPORARILY_NOT_AVAILABLE' => 'Temporarily not available',
        'APPROVED_FOR_MARKETING' => 'Approved for marketing',
        'WITHHELD' => 'Withheld',
        'UNKNOWN' => 'Unknown status',
        'none' => 'no status',
    ],
    'dates' => [
        'start_date' => 'Start date',
        'primary_completion_date' => 'Primary completion date',
        'completion_date' => 'Study completion date',
    ],
    'date_type' => [
        'ACTUAL' => 'actual',
        'ESTIMATED' => 'estimated',
    ],
    'months' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    'date_month' => ':month :year',
    'date_day' => ':month :day, :year',
    'not_set' => 'not set',

    'fact' => [
        'created' => 'New study registered with the status :status.',
        'created_no_status' => 'New study registered.',
        'status' => 'The registry entry changed from :before to :after.',
        'site_added' => 'Site added: :site (:status).',
        'site_added_no_status' => 'Site added: :site.',
        'site_removed' => 'Site removed: :site.',
        'site_updated' => 'Site :site changed from :before to :after.',
        'date' => ':name changed from :before to :after.',
        'results' => 'Results were posted.',
        'updated' => 'The registry entry was updated.',
        'more_sites' => 'Plus :count more site changes.',
    ],

    'reason' => [
        'now_recruiting' => 'Recruitment opened: the status changed to Recruiting.',
        'status_changed' => 'The overall status changed.',
        'status_in_criteria' => 'The new status, :status, is one this saved search follows.',
        'site_in_region' => 'Recruiting site in :place, a location this saved search monitors.',
        'site_added' => 'New site listed.',
        'site_removed' => 'A site was removed.',
        'results_posted' => 'Results were posted on the registry.',
        'date_changed' => 'A key date changed.',
        'new_study' => 'Newly registered study that matches this saved search.',
        'updated' => 'Registry entry updated.',
        'matches' => 'Matches this saved search: :criteria.',
    ],

    'criteria' => [
        'q' => 'text ":value"',
        'condition' => 'condition ":value"',
        'keyword' => 'keyword ":value"',
        'mesh_term' => 'MeSH term ":value"',
        'sponsor' => 'sponsor ":value"',
        'country' => 'country :value',
        'city' => 'city :value',
        'status' => 'status :value',
        'phase' => 'phase :value',
    ],

    'subject' => 'AI digest: :name (:period)',
    'title' => 'AI digest for ":name"',
    'period' => ':start to :end',
    'intro' => ':count matching study update(s) between :start and :end.',
    'intro_truncated' => ':count matching study update(s) between :start and :end. The :shown most relevant are described below.',
    'source_ai' => 'Summary written by AI from the registry facts listed.',
    'source_factual' => 'Summary built from the recorded registry changes.',
    'updated_on' => 'Registry update posted :date',
    'view_registry' => 'View on ClinicalTrials.gov',
    'view_report' => 'Open the report',
    'notice' => 'This digest describes public registry entries from ClinicalTrials.gov. It is not medical advice, does not assess eligibility for any study and does not interpret results. Verify every fact on the linked registry page.',
    'missing_fact' => 'The registry did not provide this information.',
    'manage' => 'You receive this because AI digests are on for this saved search. You can turn them off in the saved search settings; basic alerts keep working.',
];
