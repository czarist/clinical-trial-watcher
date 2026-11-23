<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

use App\Enums\ImportRunStatus;
use App\Events\ClinicalTrialsImported;
use App\Exceptions\InvalidStudyPayload;
use App\Exceptions\RegistryRequestFailed;
use App\Models\ImportRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

/**
 * Imports studies page by page, following nextPageToken, and records the run.
 *
 * Remote pages are fetched outside database transactions; each study is persisted in its
 * own transaction, so a failing study does not leave partial relations or stop the run.
 */
final readonly class ClinicalTrialImporter
{
    public function __construct(
        private StudyRegistryClient $client,
        private StudyMapper $mapper,
        private ClinicalTrialSynchronizer $synchronizer,
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {}

    public function import(ImportOptions $options): ImportRun
    {
        $run = ImportRun::create([
            'status' => ImportRunStatus::Running,
            'updated_since' => $this->updatedSince($options),
            'started_at' => now(),
        ]);

        try {
            $reachedPageLimit = $this->importPages($run, $options);
            $run->status = $run->studies_failed > 0 || $reachedPageLimit
                ? ImportRunStatus::Partial
                : ImportRunStatus::Succeeded;
            $run->error = $reachedPageLimit ? "Stopped after {$run->pages} page(s) because of the page limit." : null;
        } catch (RegistryRequestFailed $exception) {
            $run->status = ImportRunStatus::Failed;
            $run->error = $exception->getMessage();
        } catch (\Throwable $exception) {
            $run->status = ImportRunStatus::Failed;
            $run->error = 'Unexpected error: '.$exception->getMessage();

            throw $exception;
        } finally {
            $run->finished_at = now();
            $run->save();
        }

        $this->logger->log(
            $run->status === ImportRunStatus::Failed ? 'error' : 'info',
            'ClinicalTrials.gov import finished',
            $run->summary() + ['error' => $run->error],
        );

        $this->events->dispatch(new ClinicalTrialsImported($run));

        return $run;
    }

    /**
     * @return bool true when pages remained but the page limit was reached
     */
    private function importPages(ImportRun $run, ImportOptions $options): bool
    {
        $pageToken = null;

        do {
            $page = $this->client->fetchPage($options->pageSize, $pageToken, $run->updated_since?->toImmutable());
            $run->pages++;

            foreach ($page->studies as $payload) {
                $this->importStudy($payload, $run);
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
    private function importStudy(array $payload, ImportRun $run): void
    {
        try {
            $outcome = $this->synchronizer->sync($this->mapper->map($payload), $run);
        } catch (InvalidStudyPayload|QueryException $exception) {
            $run->studies_failed++;
            $this->logger->warning('Clinical trial could not be imported', [
                'nct_id' => $payload['protocolSection']['identificationModule']['nctId'] ?? null,
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

        $lastStart = ImportRun::query()->succeeded()->latest('started_at')->value('started_at');

        return $lastStart !== null ? CarbonImmutable::parse($lastStart)->startOfDay() : null;
    }
}
