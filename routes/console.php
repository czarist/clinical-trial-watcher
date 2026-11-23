<?php

declare(strict_types=1);

use App\Jobs\ImportClinicalTrialsJob;
use App\Services\ClinicalTrials\Import\ImportOptions;
use Illuminate\Support\Facades\Schedule;

// Incremental import: studies updated since the last successful run (the first run reads everything).
Schedule::job(new ImportClinicalTrialsJob(new ImportOptions(pageSize: (int) config('clinicaltrials.page_size'))))
    ->dailyAt((string) config('clinicaltrials.schedule_time'))
    ->name('clinicaltrials:import')
    ->withoutOverlapping();

Schedule::command('alerts:send-digests daily')->dailyAt('07:00');
Schedule::command('alerts:send-digests weekly')->weeklyOn(1, '07:00');

// AI digests for the window that just ended, after the morning basic digests. Each edition has
// a stable identity, so an overlapping or repeated run does not send twice.
Schedule::command('digests:dispatch daily')->dailyAt('07:30')->withoutOverlapping()->onOneServer();
Schedule::command('digests:dispatch weekly')->weeklyOn(1, '07:30')->withoutOverlapping()->onOneServer();
