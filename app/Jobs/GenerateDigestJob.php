<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Alert;
use App\Services\Digests\DigestGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Builds and sends one digest edition outside the web request and the import transaction.
 * Unique per saved search and window while queued; retries reuse the same edition.
 */
final class GenerateDigestJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $alertId,
        public readonly string $periodStart,
        public readonly string $periodEnd,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->alertId}:{$this->periodStart}/{$this->periodEnd}";
    }

    public function handle(DigestGenerator $generator): void
    {
        $alert = Alert::query()->find($this->alertId);

        if ($alert === null) {
            return;
        }

        $generator->generate($alert, CarbonImmutable::parse($this->periodStart), CarbonImmutable::parse($this->periodEnd));
    }
}
