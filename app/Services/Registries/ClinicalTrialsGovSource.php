<?php

declare(strict_types=1);

namespace App\Services\Registries;

use App\Services\ClinicalTrials\Import\StudyMapper;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Digests\RegistryLink;

/**
 * ClinicalTrials.gov (API v2). Records are identified by their NCT number.
 */
final readonly class ClinicalTrialsGovSource implements RegistrySource
{
    public const string KEY = 'clinicaltrials_gov';

    public function __construct(
        private StudyRegistryClient $client,
        private StudyMapper $mapper,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'ClinicalTrials.gov';
    }

    public function client(): StudyRegistryClient
    {
        return $this->client;
    }

    public function mapper(): StudyPayloadMapper
    {
        return $this->mapper;
    }

    public function recordUrl(string $sourceId): ?string
    {
        return RegistryLink::forNctId($sourceId);
    }
}
