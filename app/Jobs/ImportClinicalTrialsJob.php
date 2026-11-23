<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ClinicalTrials\Import\ClinicalTrialImporter;
use App\Services\ClinicalTrials\Import\ImportOptions;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs a registry import on the queue. Only one import per registry runs at a time.
 */
final class ImportClinicalTrialsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 6 * 60 * 60;

    public int $uniqueFor = 6 * 60 * 60;

    public function __construct(public readonly ImportOptions $options = new ImportOptions) {}

    public function uniqueId(): string
    {
        return $this->options->source;
    }

    public function handle(ClinicalTrialImporter $importer): void
    {
        $importer->import($this->options);
    }
}
