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
 *
 * A study registered in several registries is one study: a change to any of its records is
 * matched against the record shown for it, and the match points to that record. The same
 * news reported by two registries is matched once: a duplicate registration appearing is not
 * a new study for an alert that already knows it, and a status already matched recently for
 * the study is not matched again.
 */
final readonly class AlertMatcher
{
    private const int INSERT_CHUNK = 500;

    /** Days during which the same status of a study, reported again by another registry, is not matched twice. */
    private const int REPEATED_STATUS_DAYS = 30;

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
            // The changed record is the study's shown record or one of its other registrations.
            ->join('clinical_trials as changed_record', fn ($join) => $join
                ->on('changed_record.id', '=', 'clinical_trials.id')
                ->orOn('changed_record.canonical_trial_id', '=', 'clinical_trials.id'))
            ->join('clinical_trial_changes as trial_change', 'trial_change.clinical_trial_id', '=', 'changed_record.id')
            ->where('trial_change.import_run_id', $run->id)
            ->where('trial_change.created_at', '>=', $alert->created_at)
            // A retried job must not match (and notify) the same change twice.
            ->whereNotExists(fn ($matched) => $matched->from('alert_matches')
                ->whereColumn('alert_matches.clinical_trial_change_id', 'trial_change.id')
                ->where('alert_matches.alert_id', $alert->id))
            ->select(['clinical_trials.id as trial_id', 'trial_change.id as change_id', 'trial_change.type as change_type', 'trial_change.new_status', 'changed_record.id as record_id'])
            ->chunkById(self::INSERT_CHUNK, function (Collection $rows) use ($alert, $now, &$created): void {
                $records = $this->withoutRepeats($alert, $rows)->map(fn (object $row): array => [
                    'alert_id' => $alert->id,
                    'clinical_trial_id' => $row->trial_id,
                    'clinical_trial_change_id' => $row->change_id,
                    'reason' => $this->reason(TrialChangeType::from($row->change_type), $alert)->value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                if ($records !== []) {
                    AlertMatch::query()->insert($records);
                    $created += count($records);
                }
            }, 'trial_change.id', 'change_id');

        return $created;
    }

    /**
     * Drops changes that repeat what the alert already matched for the same study, usually
     * because another registry reported it.
     *
     * @param  Collection<int, object{trial_id: int, change_id: int, change_type: string, new_status: ?string, record_id: int}>  $rows
     * @return Collection<int, object{trial_id: int, change_id: int, change_type: string, new_status: ?string, record_id: int}>
     */
    private function withoutRepeats(Alert $alert, Collection $rows): Collection
    {
        $earlier = AlertMatch::query()
            ->where('alert_id', $alert->id)
            ->whereIn('clinical_trial_id', $rows->pluck('trial_id')->unique())
            ->with('change:id,type,new_status,clinical_trial_id')
            ->get(['id', 'clinical_trial_id', 'clinical_trial_change_id', 'created_at']);

        $known = $earlier->pluck('clinical_trial_id')->flip()->all();
        $recentStatuses = $earlier
            ->filter(fn (AlertMatch $match): bool => $match->created_at?->gte(now()->subDays(self::REPEATED_STATUS_DAYS)) && $match->change?->new_status !== null)
            ->groupBy(fn (AlertMatch $match): string => $match->clinical_trial_id.'|'.$match->change?->new_status)
            ->map(fn (Collection $matches): array => $matches->pluck('change.clinical_trial_id')->flip()->all())
            ->all();
        $updatedInThisRun = [];

        return $rows->filter(function (object $row) use (&$known, &$recentStatuses, &$updatedInThisRun): bool {
            $type = TrialChangeType::from($row->change_type);
            $statusKey = $row->trial_id.'|'.$row->new_status;

            $repeat = match ($type) {
                // Another registry listing a study the alert already follows is not a new study.
                TrialChangeType::Created => isset($known[$row->trial_id]),
                // The same status reported by another registration of the study; the same record
                // returning to a status is real news.
                TrialChangeType::StatusChanged => array_diff_key($recentStatuses[$statusKey] ?? [], [$row->record_id => true]) !== [],
                TrialChangeType::Updated => isset($updatedInThisRun[$row->trial_id]),
            };

            if ($repeat) {
                return false;
            }

            $known[$row->trial_id] = true;
            $updatedInThisRun[$row->trial_id] = true;

            if ($row->new_status !== null && $type !== TrialChangeType::Updated) {
                $recentStatuses[$statusKey][$row->record_id] = true;
            }

            return true;
        })->values();
    }

    private function reason(TrialChangeType $type, Alert $alert): AlertMatchReason
    {
        if ($type === TrialChangeType::StatusChanged && ! $alert->notify_status_changes) {
            return AlertMatchReason::UpdatedStudy;
        }

        return $type->alertReason();
    }
}
