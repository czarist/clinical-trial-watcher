<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AlertFrequency;
use App\Services\Alerts\AlertNotifier;
use Illuminate\Console\Command;

final class SendAlertDigests extends Command
{
    protected $signature = 'alerts:send-digests {frequency : daily or weekly}';

    protected $description = 'Email pending alert matches to users with daily or weekly alerts';

    public function handle(AlertNotifier $notifier): int
    {
        $frequency = AlertFrequency::tryFrom((string) $this->argument('frequency'));

        if ($frequency === null || ! $frequency->isDigest()) {
            $this->error('The frequency must be "daily" or "weekly".');

            return self::INVALID;
        }

        $sent = $notifier->sendDigests($frequency);
        $this->info("Sent {$sent} {$frequency->value} digest(s).");

        return self::SUCCESS;
    }
}
