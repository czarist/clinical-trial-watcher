<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\AlertFrequency;
use App\Exceptions\AlertLimitReached;
use App\Models\Alert;
use App\Models\User;
use App\Services\Billing\PlanResolver;
use App\Services\ClinicalTrials\Search\TrialSearchCriteria;

/**
 * Creates and changes saved-search alerts, enforcing the plan's alerts_limit.
 */
final readonly class AlertService
{
    public function __construct(private PlanResolver $plans) {}

    public function limitFor(User $user): int
    {
        return $this->plans->effectivePlan($user)?->alertsLimit() ?? 0;
    }

    public function create(User $user, string $name, TrialSearchCriteria $criteria, AlertFrequency $frequency, bool $notifyStatusChanges): Alert
    {
        $limit = $this->limitFor($user);

        if ($user->alerts()->count() >= $limit) {
            throw AlertLimitReached::forLimit($limit);
        }

        return $user->alerts()->create([
            'name' => $name,
            'criteria' => $criteria->toArray(),
            'frequency' => $frequency,
            'notify_status_changes' => $notifyStatusChanges,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array{name?: string, criteria?: TrialSearchCriteria, frequency?: AlertFrequency, notify_status_changes?: bool, is_active?: bool}  $changes
     */
    public function update(Alert $alert, array $changes): Alert
    {
        if (isset($changes['criteria'])) {
            $changes['criteria'] = $changes['criteria']->toArray();
        }

        $alert->update($changes);

        return $alert;
    }

    public function delete(Alert $alert): void
    {
        $alert->delete();
    }
}
