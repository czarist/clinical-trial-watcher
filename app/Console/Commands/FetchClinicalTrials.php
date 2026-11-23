<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\ImportRunStatus;
use App\Jobs\ImportClinicalTrialsJob;
use App\Services\ClinicalTrials\Import\ClinicalTrialImporter;
use App\Services\ClinicalTrials\Import\ImportOptions;
use App\Services\Registries\SourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class FetchClinicalTrials extends Command
{
    protected $signature = 'clinicaltrials:fetch
        {--pageSize=100 : Studies per page (1-1000)}
        {--since= : Import studies updated on or after this date (YYYY-MM-DD)}
        {--full : Read the whole registry instead of changes since the last successful run}
        {--max-pages= : Stop after this many pages}
        {--queue : Dispatch the import to the queue instead of running it now}
        {--source=clinicaltrials_gov : Registry to import from (a key of config/registries.php)}
        {--from-cache : Parse the stored copies of the pages instead of fetching them (use the same --since or --full as the run being repeated)}';

    protected $description = 'Import studies from a registry (ClinicalTrials.gov by default), following every page';

    public function handle(ClinicalTrialImporter $importer, SourceRegistry $sources): int
    {
        $validator = Validator::make($this->options(), [
            'source' => ['required', 'string', Rule::in($sources->keys())],
            'pageSize' => ['required', 'integer', 'between:1,1000'],
            'since' => ['nullable', 'date_format:Y-m-d'],
            'max-pages' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::INVALID;
        }

        $options = new ImportOptions(
            pageSize: (int) $this->option('pageSize'),
            full: (bool) $this->option('full'),
            updatedSince: $this->option('since') !== null ? CarbonImmutable::parse($this->option('since')) : null,
            maxPages: $this->option('max-pages') !== null ? (int) $this->option('max-pages') : null,
            source: (string) $this->option('source'),
            fromCache: (bool) $this->option('from-cache'),
        );

        if ($this->option('queue')) {
            ImportClinicalTrialsJob::dispatch($options);
            $this->info('Import dispatched to the queue.');

            return self::SUCCESS;
        }

        $this->info("Importing studies from {$sources->name($options->source)}...");
        $run = $importer->import($options);

        $this->table(['Run', 'Source', 'Status', 'Since', 'Pages', 'Created', 'Updated', 'Unchanged', 'Failed'], [array_values($run->summary())]);

        if ($run->error !== null) {
            $run->status === ImportRunStatus::Failed ? $this->error($run->error) : $this->warn($run->error);
        }

        return $run->status === ImportRunStatus::Failed ? self::FAILURE : self::SUCCESS;
    }
}
