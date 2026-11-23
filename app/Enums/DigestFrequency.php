<?php

declare(strict_types=1);

namespace App\Enums;

use Carbon\CarbonImmutable;

enum DigestFrequency: string
{
    case Daily = 'daily';
    case Weekly = 'weekly';

    /**
     * The reporting window that ends at the start of the current period: yesterday for a
     * daily digest, last Monday to Sunday for a weekly one.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} start (inclusive) and end (exclusive)
     */
    public function windowEndingBefore(CarbonImmutable $now): array
    {
        $end = match ($this) {
            self::Daily => $now->startOfDay(),
            self::Weekly => $now->startOfWeek(CarbonImmutable::MONDAY),
        };

        return [$this === self::Daily ? $end->subDay() : $end->subWeek(), $end];
    }
}
