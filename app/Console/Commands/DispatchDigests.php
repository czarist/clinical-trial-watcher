<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\DigestFrequency;
use App\Jobs\GenerateDigestJob;
use App\Models\Alert;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Queues the AI digests of every saved search that has them on, for the window that just
 * ended (yesterday for daily, last week for weekly). Running it twice for the same window
 * produces no second edition: each job reuses the edition's stable identity.
 */
final class DispatchDigests extends Command
{
    protected $signature = 'digests:dispatch
        {frequency : daily or weekly}
        {--end= : Date the window ends on (exclusive), e.g. tomorrow to include today; default: the start of today or of this week}';

    protected $description = 'Queue the AI digests for the reporting window that just ended';

    public function handle(): int
    {
        $frequency = DigestFrequency::tryFrom((string) $this->argument('frequency'));

        if ($frequency === null) {
            $this->error('The frequency must be daily or weekly.');

            return self::INVALID;
        }

        $reference = $this->option('end') !== null ? CarbonImmutable::parse((string) $this->option('end'))->startOfDay() : CarbonImmutable::now();
        [$start, $end] = $frequency->windowEndingBefore($reference);
        $queued = 0;

        Alert::query()
            ->active()
            ->where('ai_digest_enabled', true)
            ->where('ai_digest_frequency', $frequency)
            ->select('id')
            ->chunkById(500, function (Collection $alerts) use ($start, $end, &$queued): void {
                foreach ($alerts as $alert) {
                    GenerateDigestJob::dispatch($alert->id, $start->toIso8601String(), $end->toIso8601String());
                    $queued++;
                }
            });

        $this->info("Queued {$queued} {$frequency->value} digest(s) for {$start->toDateString()} to {$end->subDay()->toDateString()}.");

        return self::SUCCESS;
    }
}
