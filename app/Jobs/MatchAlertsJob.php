<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportRun;
use App\Services\Alerts\AlertMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class MatchAlertsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly ImportRun $run) {}

    public function handle(AlertMatcher $matcher): void
    {
        $matcher->matchImportRun($this->run);
    }
}
