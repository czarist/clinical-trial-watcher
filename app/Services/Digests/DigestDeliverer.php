<?php

declare(strict_types=1);

namespace App\Services\Digests;

use App\Models\Digest;
use App\Models\DigestDelivery;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AiDigestNotification;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Sends a generated digest to each authorized recipient once.
 *
 * Entitlement and team membership are checked again right before sending. Each recipient has
 * one delivery row; a worker claims it before sending, so a retried or concurrent job does not
 * send twice. A claim left by a crashed worker expires after CLAIM_TIMEOUT_MINUTES.
 */
final readonly class DigestDeliverer
{
    private const int CLAIM_TIMEOUT_MINUTES = 15;

    public function __construct(private Entitlements $entitlements) {}

    /**
     * @return int number of recipients sent to now
     */
    public function deliver(Digest $digest): int
    {
        $sent = 0;

        foreach ($this->recipients($digest) as $recipient) {
            $delivery = DigestDelivery::query()->firstOrCreate(['digest_id' => $digest->id, 'user_id' => $recipient->id]);

            if ($delivery->sent_at !== null || ! $this->claim($delivery)) {
                continue;
            }

            try {
                $recipient->notifyNow(new AiDigestNotification($digest));
            } catch (Throwable $exception) {
                $delivery->forceFill(['claimed_at' => null])->save();

                throw $exception;
            }

            $delivery->forceFill(['sent_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }

    /**
     * People allowed to receive the digest now.
     *
     * @return Collection<int, User>
     */
    public function recipients(Digest $digest): Collection
    {
        if ($digest->team_id !== null) {
            $team = Team::query()->with('owner')->find($digest->team_id);

            if ($team === null || ! $this->entitlements->teamIsActive($team)) {
                return collect();
            }

            return $team->members()
                ->wherePivot('receives_digests', true)
                ->whereNotNull('users.email_verified_at')
                ->get();
        }

        $user = $digest->user;

        if ($user === null || ! $user->hasVerifiedEmail() || ! $this->entitlements->hasPersonalAiDigests($user)) {
            return collect();
        }

        return collect([$user]);
    }

    private function claim(DigestDelivery $delivery): bool
    {
        return DigestDelivery::query()
            ->whereKey($delivery->id)
            ->whereNull('sent_at')
            ->where(fn ($query) => $query->whereNull('claimed_at')->orWhere('claimed_at', '<', now()->subMinutes(self::CLAIM_TIMEOUT_MINUTES)))
            ->update(['claimed_at' => now()]) === 1;
    }
}
