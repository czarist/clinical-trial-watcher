<?php

declare(strict_types=1);

namespace App\Services\Digests;

/**
 * Official ClinicalTrials.gov study links, built only from validated NCT numbers.
 */
final class RegistryLink
{
    public static function isValidNctId(?string $nctId): bool
    {
        return is_string($nctId) && preg_match('/^NCT\d{8}$/', $nctId) === 1;
    }

    public static function forNctId(?string $nctId): ?string
    {
        return self::isValidNctId($nctId) ? 'https://clinicaltrials.gov/study/'.$nctId : null;
    }
}
