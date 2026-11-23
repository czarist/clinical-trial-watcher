<?php

declare(strict_types=1);

namespace App\Enums;

enum AlertFrequency: string
{
    case Instant = 'instant';
    case Daily = 'daily';
    case Weekly = 'weekly';

    public function isDigest(): bool
    {
        return $this !== self::Instant;
    }
}
