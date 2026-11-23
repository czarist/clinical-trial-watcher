<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Registries\Deduplication\DuplicateReconciler;
use App\Services\Registries\SourceRegistry;
use Illuminate\Console\Command;

/**
 * Indexes the registry identifiers of stored studies and groups duplicate registrations.
 * Imports do this for every study they save; run this once after upgrading, or after the
 * identifier rules change.
 */
final class ReconcileDuplicateTrials extends Command
{
    protected $signature = 'trials:reconcile-duplicates {--source= : Only records of this registry}';

    protected $description = 'Index registry identifiers of stored studies and group duplicate registrations';

    public function handle(DuplicateReconciler $reconciler, SourceRegistry $sources): int
    {
        $source = $this->option('source');

        if ($source !== null && ! $sources->has((string) $source)) {
            $this->error("Unknown registry source [{$source}]. Known: ".implode(', ', $sources->keys()));

            return self::INVALID;
        }

        $result = $reconciler->reconcileAll($source !== null ? (string) $source : null, fn (int $done) => $this->line("{$done} record(s) processed"));

        $this->info("{$result['records']} record(s) processed; {$result['grouped']} shown as part of another study; {$result['conflicts']} link(s) waiting for review.");

        return self::SUCCESS;
    }
}
