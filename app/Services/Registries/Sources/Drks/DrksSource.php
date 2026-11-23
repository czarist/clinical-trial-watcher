<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Drks;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * German Clinical Trials Register (DRKS), read from its full JSON export. Only records
 * published under CC BY 4.0 (registered or last updated from 2025) are imported; older
 * records may be under the registrants' copyright and are skipped by the client.
 */
final readonly class DrksSource implements RegistrySource
{
    public const string KEY = 'drks';

    private DrksClient $client;

    private DrksMapper $mapper;

    public function __construct(RegistryFetcher $fetcher)
    {
        $this->client = new DrksClient($fetcher, $this);
        $this->mapper = new DrksMapper;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'DRKS';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Download,
            termsUrl: 'https://www.bfarm.de/EN/BfArM/Tasks/German-Clinical-Trials-Register/Search-studies/_artikel.html?nn=1523448#terms-of-use',
            licence: 'Records registered or last updated from 2025 are under CC BY 4.0 (reuse, including commercial, with attribution); older records may be under the registrants\' copyright and are not imported.',
            attribution: 'Source: German Clinical Trials Register (DRKS), BfArM, https://drks.de, retrieved on the import date. Licence: CC BY 4.0.',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            minIntervalMs: 10_000,
            commercialUse: true,
        );
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
        return preg_match('/^DRKS\d{8}$/', $sourceId) === 1 ? 'https://drks.de/search/en/trial/'.$sourceId : null;
    }
}
