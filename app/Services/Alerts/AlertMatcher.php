<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\AlertFrequency;
use App\Enums\AlertMatchReason;
use App\Enums\TrialChangeType;
use App\Models\Alert;
use App\Models\AlertMatch;
use App\Models\ImportRun;
use App\Services\ClinicalTrials\Search\TrialQuery;
use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Support\Collection;

/**
 * Matches the studies created or changed by an import against every active alert.
 *
 * Only changes recorded after an alert was created can match it, so a new alert is not
 * flooded with studies imported before it existed. Instant alerts are notified at once;
 * daily and weekly alerts wait for their digest.
 */
final readonly class AlertMatcher
{
    private const int INSERT_CHUNK = 500;

    public function __construct(
        private TrialQuery $query,
        private AlertNotifier $notifier,
    ) {}

    /**
     * @return int number of matches created
     */
    public function matchImportRun(ImportRun $run): int
    {
        if (! $run->trialChanges()->exists()) {
            return 0;
        }

        $total = 0;

        Alert::query()->active()->with('user')->chunkById(100, function (Collection $alerts) use ($run, &$total): void {
            foreach ($alerts as $alert) {
                $created = $this->matchAlert($alert, $run);
                $total += $created;

                if ($created > 0 && $alert->frequency === AlertFrequency::Instant) {
                    $this->notifier->sendPending($alert);
                }
            }
        });

        return $total;
    }

    private function matchAlert(Alert $alert, ImportRun $run): int
    {
        $criteria = TrialSearchCriteria::fromArray($alert->criteria ?? []);
        $now = now();
        $created = 0;

        $this->query->build($criteria)
            ->toBase()
            ->join('clinical_trial_changes as trial_change', 'trial_change.clinical_trial_id', '=', 'clinical_trials.id')
            ->where('trial_change.import_run_id', $run->id)
            ->where('trial_change.created_at', '>=', $alert->created_at)
            ->select(['clinical_trials.id as trial_id', 'trial_change.id as change_id', 'trial_change.type as change_type'])
            ->orderBy('trial_change.id')
            ->chunk(self::INSERT_CHUNK, function (Collection $rows) use ($alert, $now, &$created): void {
                $records = $rows->map(fn (object $row): array => [
                    'alert_id' => $alert->id,
                    'clinical_trial_id' => $row->trial_id,
                    'clinical_trial_change_id' => $row->change_id,
                    'reason' => $this->reason(TrialChangeType::from($row->change_type), $alert)->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                AlertMatch::query()->insert($records);
                $created += count($records);
            });

        return $created;
    }

    private function reason(TrialChangeType $type, Alert $alert): AlertMatchReason
    {
        if ($type === TrialChangeType::StatusChanged && ! $alert->notify_status_changes) {
            return AlertMatchReason::UpdatedStudy;
        }

        return $type->alertReason();
    }
}
