<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

enum SyncOutcome
{
    case Created;
    case Updated;
    case Unchanged;
}
