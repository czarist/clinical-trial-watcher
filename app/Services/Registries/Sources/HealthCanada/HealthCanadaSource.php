<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\HealthCanada;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * Health Canada Clinical Trials Database (phase I-III drug trials authorized in Canada), read
 * through its open JSON API. Records are identified by the numeric protocol id.
 */
final class HealthCanadaSource implements RegistrySource
{
    public const string KEY = 'health_canada';

    private ?HealthCanadaClient $client = null;

    public function __construct(private readonly RegistryFetcher $fetcher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Health Canada Clinical Trials Database';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Api,
            termsUrl: 'https://open.canada.ca/en/open-government-licence-canada',
            licence: 'Open Government Licence – Canada: worldwide, royalty-free reuse, including commercial, with attribution.',
            attribution: 'Source: Health Canada Clinical Trials Database. Contains information licensed under the Open Government Licence – Canada.',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            minIntervalMs: null,
            commercialUse: true,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client ??= new HealthCanadaClient(
            $this->fetcher,
            $this,
            (string) config('registries.options.health_canada.base_url', 'https://health-products.canada.ca/api/clinical-trial'),
            (string) config('registries.options.health_canada.lang', 'en'),
        );
    }

    public function mapper(): StudyPayloadMapper
    {
        return new HealthCanadaMapper;
    }

    /**
     * The database has no public page per record: its former web interface now redirects to
     * Canada's clinical trial search portal, whose pages use unrelated identifiers.
     */
    public function recordUrl(string $sourceId): ?string
    {
        return null;
    }
}
