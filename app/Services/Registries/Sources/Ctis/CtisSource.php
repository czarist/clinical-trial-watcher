<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Ctis;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * EU Clinical Trials Information System (CTIS), read through the JSON endpoints of its public
 * portal (euclinicaltrials.eu). EMA documents no API: the endpoints are those the portal itself
 * calls and may change without notice. Records are identified by their EU CT number
 * (YYYY-5NNNNN-NN-NN).
 */
final class CtisSource implements RegistrySource
{
    public const string KEY = 'ctis';

    public const string ID_PATTERN = '/^(?:19|20)\d{2}-5\d{5}-\d{2}-\d{2}$/';

    private ?CtisClient $client = null;

    public function __construct(private readonly RegistryFetcher $fetcher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'CTIS';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Api,
            termsUrl: 'https://www.ema.europa.eu/en/about-us/about-website/legal-notice',
            licence: 'EMA legal notice: information on EMA web pages may be reproduced for non-commercial and commercial purposes provided EMA is acknowledged as the source; content supplied by third parties is excluded. Coverage of the CTIS portal (sponsor-supplied data) is unconfirmed. The portal API is undocumented; euclinicaltrials.eu has no robots.txt (404).',
            attribution: 'Source: EMA, Clinical Trials Information System (CTIS)',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            minIntervalMs: 3000,
            commercialUse: true,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client ??= new CtisClient(
            $this->fetcher,
            $this,
            (string) config('registries.options.ctis.base_url', 'https://euclinicaltrials.eu/ctis-public-api'),
            (int) config('registries.options.ctis.max_page_size', 50),
        );
    }

    public function mapper(): StudyPayloadMapper
    {
        return new CtisMapper;
    }

    public function recordUrl(string $sourceId): ?string
    {
        return preg_match(self::ID_PATTERN, $sourceId) === 1
            ? 'https://euclinicaltrials.eu/search-for-clinical-trials/?lang=en&EUCT='.$sourceId
            : null;
    }
}
