<?php

declare(strict_types=1);

return [

    /*
    | ClinicalTrials.gov API v2. TLS certificates are always verified.
    */
    'base_url' => env('CLINICAL_TRIALS_BASE_URL', 'https://clinicaltrials.gov/api/v2'),

    'page_size' => (int) env('CLINICAL_TRIALS_PAGE_SIZE', 100),

    'timeout' => (int) env('CLINICAL_TRIALS_TIMEOUT', 60),

    /*
    | Bounded retries with exponential backoff for rate limits (HTTP 429),
    | server errors (5xx) and connection failures.
    */
    'retry' => [
        'times' => (int) env('CLINICAL_TRIALS_RETRY_TIMES', 3),
        'sleep_ms' => (int) env('CLINICAL_TRIALS_RETRY_SLEEP_MS', 1000),
    ],

    /*
    | Time of the scheduled daily import (server timezone).
    */
    'schedule_time' => env('CLINICAL_TRIALS_SCHEDULE_TIME', '03:00'),

];
