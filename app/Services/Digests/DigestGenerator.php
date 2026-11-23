<?php

declare(strict_types=1);

namespace App\Services\Digests;

use App\Enums\DigestStatus;
use App\Models\Alert;
use App\Models\Digest;
use App\Services\Billing\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Produces and sends one digest edition for a saved search and reporting window.
 *
 * The edition has a stable identity (recipient or workspace, saved search and window), so
 * repeated scheduler runs and job retries reuse it instead of creating another. A cache lock
 * keeps two workers from building the same edition at once. Paid access is checked before
 * building and again before sending.
 */
final readonly class DigestGenerator
{
    private const int LOCK_SECONDS = 600;

    public function __construct(
        private DigestBuilder $builder,
        private DigestDeliverer $deliverer,
        private Entitlements $entitlements,
    ) {}

    public static function identity(Alert $alert, CarbonImmutable $start, CarbonImmutable $end): string
    {
        $scope = $alert->team_id !== null ? "team:{$alert->team_id}" : "user:{$alert->user_id}";

        return "{$scope}:alert:{$alert->id}:{$start->toIso8601String()}/{$end->toIso8601String()}";
    }

    public function generate(Alert $alert, CarbonImmutable $start, CarbonImmutable $end): ?Digest
    {
        $alert->loadMissing(['user', 'team.owner']);

        if (! $alert->ai_digest_enabled || ! $alert->is_active) {
            return null;
        }

        if (! $this->isEntitled($alert)) {
            Log::info('digests.skipped_no_entitlement', ['alert_id' => $alert->id]);

            return null;
        }

        $identity = self::identity($alert, $start, $end);
        $lock = Cache::lock('digest:'.hash('sha256', $identity), self::LOCK_SECONDS);

        if (! $lock->get()) {
            return null;
        }

        try {
            $digest = Digest::query()->firstOrCreate(['identity' => $identity], [
                'alert_id' => $alert->id,
                'user_id' => $alert->team_id === null ? $alert->user_id : null,
                'team_id' => $alert->team_id,
                'frequency' => $alert->ai_digest_frequency,
                'language' => $alert->ai_digest_language,
                'period_start' => $start,
                'period_end' => $end,
                'status' => DigestStatus::Pending,
            ]);

            if ($digest->status === DigestStatus::Pending) {
                $this->builder->build($digest, $alert);
            }

            if ($digest->status === DigestStatus::Generated) {
                $this->deliverer->deliver($digest);
            }

            return $digest;
        } finally {
            $lock->release();
        }
    }

    private function isEntitled(Alert $alert): bool
    {
        if ($alert->team !== null) {
            return $this->entitlements->teamIsActive($alert->team);
        }

        return $alert->user !== null && $this->entitlements->hasPersonalAiDigests($alert->user);
    }
}
