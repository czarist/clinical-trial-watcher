<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

/**
 * Normalizes registry dates to YYYY-MM-DD.
 *
 * Partial dates are completed with the first day (2024 → 2024-01-01, 2024-06 → 2024-06-01).
 * A normalized value is not evidence that the registry supplied that exact day.
 */
final class RegistryDate
{
    public static function normalize(mixed $date): ?string
    {
        if (! is_string($date) || $date === '') {
            return null;
        }

        return match (1) {
            preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) => $date,
            preg_match('/^\d{4}-\d{2}$/', $date) => $date.'-01',
            preg_match('/^\d{4}$/', $date) => $date.'-01-01',
            default => null,
        };
    }
}
