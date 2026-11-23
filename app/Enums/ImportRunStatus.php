<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportRunStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    /** Every page was not read (page limit) or some studies failed. */
    case Partial = 'partial';
    case Failed = 'failed';
}
