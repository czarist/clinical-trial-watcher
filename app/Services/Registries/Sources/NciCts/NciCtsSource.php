<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\NciCts;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * NCI Clinical Trials Search API v2: the cancer trials of NCI's Clinical Trials Reporting
 * Program (CTRP), read with a free developer key. Records are identified by their NCI id
 * (NCI-YYYY-NNNNN); nearly every one cites its NCT number, so the records are linked to the
 * ClinicalTrials.gov registrations the application already imports.
 */
final class NciCtsSource implements RegistrySource
{
    public const string KEY = 'nci_cts';

    public const string ID_PATTERN = '/^NCI-\d{4}-\d{5}$/';

    private ?NciCtsClient $client = null;

    public function __construct(private readonly RegistryFetcher $fetcher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'NCI Clinical Trials Search';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Api,
            termsUrl: 'https://www.cancer.gov/policies/copyright-reuse',
            licence: 'NCI text is free of copyright and may be reused without permission, including commercially, with credit to the National Cancer Institute (no NCI logo). The API key agreement (https://clinicaltrialsapi.cancer.gov/terms) could not be read during the review and may add conditions such as rate limits or key non-sharing; read it when requesting the key.',
            attribution: 'Source: National Cancer Institute Clinical Trials Search API',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            // A third-party note (unverified) gives 1,000 requests a day per key.
            minIntervalMs: 3000,
            commercialUse: true,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client ??= new NciCtsClient(
            $this->fetcher,
            $this,
            (string) config('registries.options.nci_cts.base_url', NciCtsClient::BASE_URL),
            config('registries.options.nci_cts.api_key'),
            (array) config('registries.options.nci_cts.filters', []),
        );
    }

    public function mapper(): StudyPayloadMapper
    {
        return new NciCtsMapper;
    }

    public function recordUrl(string $sourceId): ?string
    {
        return preg_match(self::ID_PATTERN, $sourceId) === 1
            ? 'https://www.cancer.gov/research/participate/clinical-trials-search/v?id='.$sourceId
            : null;
    }
}
