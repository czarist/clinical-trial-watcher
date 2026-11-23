<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

use App\Exceptions\RegistryRequestFailed;
use Carbon\CarbonInterface;

/**
 * Reads pages of studies from a clinical trial registry.
 */
interface StudyRegistryClient
{
    /**
     * @throws RegistryRequestFailed when the registry cannot be read after the allowed retries
     */
    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage;
}
