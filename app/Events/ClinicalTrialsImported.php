<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ImportRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ClinicalTrialsImported
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly ImportRun $run) {}
}
