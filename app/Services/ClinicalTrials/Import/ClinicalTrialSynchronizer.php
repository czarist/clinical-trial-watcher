<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

use App\Enums\TrialChangeType;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ImportRun;
use App\Services\ClinicalTrials\Changes\ChangeDetector;
use App\Services\ClinicalTrials\Changes\TrackedFacts;
use Illuminate\Database\ConnectionInterface;

/**
 * Persists one mapped study, its related lists and its change record atomically.
 *
 * The study is matched by NCT number. Every related list is replaced with the current one,
 * including empty lists, and only rows of this study are deleted.
 *
 * Changes: a new study is recorded as created and its tracked facts become the baseline.
 * A study imported before facts were tracked gets its baseline on this import, without
 * structured details except a status change, which the previous column shows. Otherwise the
 * previous and current facts are compared. A change record is written, in the same
 * transaction, only when a column or a tracked fact differs; its fingerprint makes it unique.
 */
final readonly class ClinicalTrialSynchronizer
{
    /** Attributes ignored when deciding whether a study changed. */
    private const array VOLATILE_ATTRIBUTES = ['raw_json', 'tracked_facts', 'updated_at'];

    public function __construct(
        private ConnectionInterface $db,
        private ChangeDetector $detector = new ChangeDetector,
    ) {}

    public function sync(MappedStudy $study, ?ImportRun $run = null): SyncOutcome
    {
        return $this->db->transaction(function () use ($study, $run): SyncOutcome {
            $trial = ClinicalTrial::query()->where('nct_id', $study->nctId)->lockForUpdate()->first()
                ?? new ClinicalTrial(['nct_id' => $study->nctId]);

            $isNew = ! $trial->exists;
            $previousStatus = $trial->overall_status;
            $previousFacts = is_array($trial->tracked_facts) ? TrackedFacts::fromArray($trial->tracked_facts) : null;

            $trial->fill($study->attributes);

            if ($study->facts !== null) {
                $trial->tracked_facts = $study->facts->toArray();
            }

            $details = $isNew ? [] : $this->details($previousFacts, $study->facts, $previousStatus, $trial->overall_status);
            $outcome = match (true) {
                $isNew => SyncOutcome::Created,
                $details !== [] || $this->hasChanges($trial) => SyncOutcome::Updated,
                default => SyncOutcome::Unchanged,
            };
            $trial->save();

            foreach ($study->relations as $relation => $rows) {
                $trial->{$relation}()->delete();

                if ($rows !== []) {
                    $trial->{$relation}()->createMany($rows);
                }
            }

            if ($outcome !== SyncOutcome::Unchanged) {
                $this->recordChange($trial, $run, $isNew, $previousStatus, $details, $previousFacts, $study->facts);
            }

            return $outcome;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function details(?TrackedFacts $before, ?TrackedFacts $after, ?string $previousStatus, ?string $status): array
    {
        if ($before !== null && $after !== null) {
            return $this->detector->diff($before, $after);
        }

        // Baseline: only the status column can be compared.
        return $previousStatus !== $status
            ? [['field' => 'overall_status', 'before' => $previousStatus, 'after' => $status]]
            : [];
    }

    private function hasChanges(ClinicalTrial $trial): bool
    {
        return array_diff(array_keys($trial->getDirty()), self::VOLATILE_ATTRIBUTES) !== [];
    }

    /**
     * @param  list<array<string, mixed>>  $details
     */
    private function recordChange(
        ClinicalTrial $trial,
        ?ImportRun $run,
        bool $isNew,
        ?string $previousStatus,
        array $details,
        ?TrackedFacts $previousFacts,
        ?TrackedFacts $facts,
    ): void {
        $statusChanged = ! $isNew && $previousStatus !== $trial->overall_status;
        $type = match (true) {
            $isNew => TrialChangeType::Created,
            $statusChanged => TrialChangeType::StatusChanged,
            default => TrialChangeType::Updated,
        };

        $fingerprint = hash('sha256', json_encode([
            $trial->nct_id,
            $type->value,
            $previousFacts?->hash(),
            $facts?->hash(),
            $trial->last_update_post_date?->toDateString(),
            $details,
        ], JSON_THROW_ON_ERROR));

        $trial->trialChanges()->firstOrCreate(['fingerprint' => $fingerprint], [
            'import_run_id' => $run?->id,
            'type' => $type,
            'previous_status' => $isNew ? null : $previousStatus,
            'new_status' => $trial->overall_status,
            'details' => $isNew ? null : $details,
        ]);
    }
}
