<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

use App\Enums\ImportRunStatus;
use App\Events\ClinicalTrialsImported;
use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryAccessRefused;
use App\Exceptions\RegistryRequestFailed;
use App\Models\ImportRun;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\LayoutDriftDetector;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * Imports studies from one registry page by page, following nextPageToken, and records the run.
 * Each registry keeps its own incremental cursor: the start of its last successful run.
 *
 * Remote pages are fetched outside database transactions; each study is persisted in its
 * own transaction, so a failing study does not leave partial relations or stop the run.
 *
 * With fromCache the pages are read from their stored copies (RegistryFetcher), so a parser
 * fix can run again without requests. A refused request (legal review, robots.txt, a block
 * by the registry) fails the run and is never retried. For registries read by download or
 * web page, a run that parses far fewer records or fields than the last one is marked
 * partial (LayoutDriftDetector).
 */
final readonly class ClinicalTrialImporter
{
    public function __construct(
        private SourceRegistry $sources,
        private ClinicalTrialSynchronizer $synchronizer,
        private Dispatcher $events,
        private LoggerInterface $logger,
        private RegistryFetcher $fetcher,
        private LayoutDriftDetector $drift,
    ) {}

    public function import(ImportOptions $options): ImportRun
    {
        $source = $this->sources->get($options->source);
        $run = ImportRun::create([
            'source' => $source->key(),
            'status' => ImportRunStatus::Running,
            'updated_since' => $this->updatedSince($options),
            'started_at' => now(),
        ]);

        $fill = new FieldFill;
        $this->fetcher->replayFromCache($options->fromCache);

        try {
            $reachedPageLimit = $this->importPages($source, $run, $options, $fill);
            $run->field_fill_ratio = $fill->ratio();
            $drift = $reachedPageLimit ? null : $this->drift->check($source, $run);
            $run->status = $run->studies_failed > 0 || $reachedPageLimit || $drift !== null
                ? ImportRunStatus::Partial
                : ImportRunStatus::Succeeded;
            $run->error = $reachedPageLimit ? "Stopped after {$run->pages} page(s) because of the page limit." : $drift;
        } catch (RegistryRequestFailed|RegistryAccessRefused $exception) {
            $run->status = ImportRunStatus::Failed;
            $run->error = $exception->getMessage();
        } catch (\Throwable $exception) {
            $run->status = ImportRunStatus::Failed;
            $run->error = 'Unexpected error: '.$exception->getMessage();

            throw $exception;
        } finally {
            $this->fetcher->replayFromCache(false);
            $run->finished_at = now();
            $run->save();
        }

        $this->logger->log(
            $run->status === ImportRunStatus::Failed ? 'error' : 'info',
            "{$source->name()} import finished",
            $run->summary() + ['error' => $run->error],
        );

        $this->events->dispatch(new ClinicalTrialsImported($run));

        return $run;
    }

    /**
     * @return bool true when pages remained but the page limit was reached
     */
    private function importPages(RegistrySource $source, ImportRun $run, ImportOptions $options, FieldFill $fill): bool
    {
        $pageToken = null;

        do {
            $page = $source->client()->fetchPage($options->pageSize, $pageToken, $run->updated_since?->toImmutable());
            $run->pages++;

            foreach ($page->studies as $payload) {
                $this->importStudy($source, $payload, $run, $fill);
            }

            $run->save();
            $pageToken = $page->nextPageToken;
            $limitReached = $options->maxPages !== null && $run->pages >= $options->maxPages;
        } while ($page->hasNextPage() && ! $limitReached);

        return $page->hasNextPage() && $limitReached;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function importStudy(RegistrySource $source, array $payload, ImportRun $run, FieldFill $fill): void
    {
        try {
            $study = $source->mapper()->map($payload);
            $fill->add($study);
            $outcome = $this->synchronizer->sync($study, $run);
        } catch (InvalidStudyPayload|QueryException $exception) {
            $run->studies_failed++;
            $this->logger->warning('Clinical trial could not be imported', [
                'source' => $source->key(),
                'source_id' => $source->mapper()->payloadId($payload),
                'error' => $exception->getMessage(),
            ]);

            return;
        }

        match ($outcome) {
            SyncOutcome::Created => $run->studies_created++,
            SyncOutcome::Updated => $run->studies_updated++,
            SyncOutcome::Unchanged => $run->studies_unchanged++,
        };
    }

    private function updatedSince(ImportOptions $options): ?CarbonImmutable
    {
        if ($options->full) {
            return null;
        }

        if ($options->updatedSince !== null) {
            return $options->updatedSince->startOfDay();
        }

        $lastStart = ImportRun::query()->where('source', $options->source)->succeeded()->latest('started_at')->value('started_at');

        return $lastStart !== null ? CarbonImmutable::parse($lastStart)->startOfDay() : null;
    }
}
