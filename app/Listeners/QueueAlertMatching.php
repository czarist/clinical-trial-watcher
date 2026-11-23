<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\ClinicalTrialsImported;
use App\Jobs\MatchAlertsJob;

/**
 * Matches alerts against the studies an import created or changed.
 */
final class QueueAlertMatching
{
    public function handle(ClinicalTrialsImported $event): void
    {
        MatchAlertsJob::dispatch($event->run);
    }
}
