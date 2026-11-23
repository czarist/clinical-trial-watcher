<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

use App\Enums\TrialChangeType;
use App\Models\ClinicalTrials\ClinicalTrial;
use App\Models\ImportRun;
use Illuminate\Database\ConnectionInterface;

/**
 * Persists one mapped study and its related lists atomically.
 *
 * The study is matched by NCT number. Every related list is replaced with the current one,
 * including empty lists, and only rows of this study are deleted. A creation, an update or
 * a change of overall status is recorded as a ClinicalTrialChange for alert matching.
 */
final readonly class ClinicalTrialSynchronizer
{
    /** Attributes ignored when deciding whether a study changed. */
    private const array VOLATILE_ATTRIBUTES = ['raw_json', 'updated_at'];

    public function __construct(private ConnectionInterface $db) {}

    public function sync(MappedStudy $study, ?ImportRun $run = null): SyncOutcome
    {
        return $this->db->transaction(function () use ($study, $run): SyncOutcome {
            $trial = ClinicalTrial::query()->where('nct_id', $study->nctId)->lockForUpdate()->first()
                ?? new ClinicalTrial(['nct_id' => $study->nctId]);

            $isNew = ! $trial->exists;
            $previousStatus = $trial->overall_status;

            $trial->fill($study->attributes);
            $outcome = match (true) {
                $isNew => SyncOutcome::Created,
                $this->hasChanges($trial) => SyncOutcome::Updated,
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
                $this->recordChange($trial, $run, $isNew, $previousStatus);
            }

            return $outcome;
        });
    }

    private function hasChanges(ClinicalTrial $trial): bool
    {
        return array_diff(array_keys($trial->getDirty()), self::VOLATILE_ATTRIBUTES) !== [];
    }

    private function recordChange(ClinicalTrial $trial, ?ImportRun $run, bool $isNew, ?string $previousStatus): void
    {
        $statusChanged = ! $isNew && $previousStatus !== $trial->overall_status;

        $trial->trialChanges()->create([
            'import_run_id' => $run?->id,
            'type' => match (true) {
                $isNew => TrialChangeType::Created,
                $statusChanged => TrialChangeType::StatusChanged,
                default => TrialChangeType::Updated,
            },
            'previous_status' => $isNew ? null : $previousStatus,
            'new_status' => $trial->overall_status,
        ]);
    }
}
