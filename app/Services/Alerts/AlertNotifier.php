<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\AlertFrequency;
use App\Models\Alert;
use App\Notifications\AlertMatchesNotification;

/**
 * Sends an alert's pending matches to its owner and marks them as notified.
 */
final class AlertNotifier
{
    /** Studies listed in one message; the rest are counted. */
    public const int MAX_LISTED = 50;

    public function sendPending(Alert $alert): bool
    {
        $pending = $alert->pendingMatches();
        $total = $pending->count();

        if ($total === 0 || $alert->user === null || ! $alert->user->hasVerifiedEmail()) {
            return false;
        }

        $matches = $alert->pendingMatches()
            ->with(['trial:id,nct_id,brief_title,overall_status', 'change:id,previous_status,new_status'])
            ->latest('id')
            ->limit(self::MAX_LISTED)
            ->get();

        $alert->user->notify(new AlertMatchesNotification($alert, $matches, $total));

        $alert->pendingMatches()->update(['notified_at' => now()]);
        $alert->forceFill(['last_notified_at' => now()])->save();

        return true;
    }

    /**
     * @return int number of alerts notified
     */
    public function sendDigests(AlertFrequency $frequency): int
    {
        $sent = 0;

        Alert::query()
            ->active()
            ->where('frequency', $frequency)
            ->whereHas('pendingMatches')
            ->with('user')
            ->chunkById(100, function ($alerts) use (&$sent): void {
                foreach ($alerts as $alert) {
                    $sent += $this->sendPending($alert) ? 1 : 0;
                }
            });

        return $sent;
    }
}
