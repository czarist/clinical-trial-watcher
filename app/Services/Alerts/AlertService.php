<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Enums\AlertFrequency;
use App\Enums\DigestFrequency;
use App\Enums\Locale;
use App\Exceptions\AlertLimitReached;
use App\Exceptions\PaidFeatureRequired;
use App\Models\Alert;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Billing\PlanResolver;
use App\Services\ClinicalTrials\Search\TrialSearchCriteria;

/**
 * Creates and changes saved searches (alerts), personal or shared by a team.
 *
 * Basic alerts are free on every plan, up to one configurable limit. AI digests need Pro AI
 * or Team AI for a personal saved search, and an active Team AI workspace for a shared one.
 * Turning AI digests off is always allowed and leaves the basic alert running.
 */
final readonly class AlertService
{
    public function __construct(
        private PlanResolver $plans,
        private Entitlements $entitlements,
    ) {}

    public function limit(): int
    {
        return $this->plans->savedSearchLimit();
    }

    /**
     * @param  array{name: string, criteria: TrialSearchCriteria, frequency: AlertFrequency, notify_status_changes?: bool, ai_digest_enabled?: bool, ai_digest_frequency?: DigestFrequency, ai_digest_language?: Locale}  $attributes
     */
    public function create(User $user, ?Team $team, array $attributes): Alert
    {
        if ($team !== null && ! $this->entitlements->teamIsActive($team)) {
            throw PaidFeatureRequired::teamWorkspace();
        }

        $existing = $team !== null ? $team->alerts()->count() : $user->alerts()->personal()->count();

        if ($existing >= $this->limit()) {
            throw AlertLimitReached::forLimit($this->limit());
        }

        if (($attributes['ai_digest_enabled'] ?? false) === true) {
            $this->assertAiDigestsAllowed($user, $team);
        }

        $attributes['criteria'] = $attributes['criteria']->toArray();

        return $user->alerts()->create([
            'notify_status_changes' => true,
            ...$attributes,
            'team_id' => $team?->id,
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $changes  validated changes; criteria as TrialSearchCriteria
     */
    public function update(Alert $alert, array $changes): Alert
    {
        if (($changes['ai_digest_enabled'] ?? false) === true && ! $alert->ai_digest_enabled) {
            $this->assertAiDigestsAllowed($alert->user, $alert->team);
        }

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

    private function assertAiDigestsAllowed(?User $user, ?Team $team): void
    {
        $allowed = $team !== null
            ? $this->entitlements->teamIsActive($team)
            : $user !== null && $this->entitlements->hasPersonalAiDigests($user);

        if (! $allowed) {
            throw $team !== null ? PaidFeatureRequired::teamWorkspace() : PaidFeatureRequired::aiDigests();
        }
    }
}
