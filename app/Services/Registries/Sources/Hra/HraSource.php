<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Hra;

use App\Enums\AccessMethod;
use App\Enums\IdentifierScheme;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * Research summaries of the UK Health Research Authority (hra.nhs.uk), read from the public
 * listing and summary pages. Records are identified by their IRAS number.
 *
 * Dormant until the HRA grants written permission: its terms allow reproduction only for
 * research or private study and forbid systematic reproduction for commercial gain, so
 * requests are refused unless `registries.permissions.hra` is enabled.
 */
final readonly class HraSource implements RegistrySource
{
    public const string KEY = 'hra';

    public const string BASE_URL = 'https://www.hra.nhs.uk';

    public const string LISTING_PATH = '/planning-and-improving-research/application-summaries/research-summaries/';

    public function __construct(
        private RegistryFetcher $fetcher,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'HRA Research Summaries';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Scraping,
            termsUrl: self::BASE_URL.'/about-us/governance/terms-and-conditions/',
            licence: '© Health Research Authority: reproduction free of charge only for research or private study; the material must not be systematically reproduced and sold for commercial gain. Written permission required from the HRA for this importer. No robots.txt is published.',
            attribution: 'Source: Health Research Authority research summaries (hra.nhs.uk). © Health Research Authority',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: (bool) config('registries.permissions.'.self::KEY, false),
            commercialUse: false,
        );
    }

    public function client(): StudyRegistryClient
    {
        return new HraClient($this->fetcher, $this);
    }

    public function mapper(): StudyPayloadMapper
    {
        return new HraMapper;
    }

    /**
     * Summary pages are addressed by a slug of their title, not by IRAS number, so the link is
     * a search of the listing for that number.
     */
    public function recordUrl(string $sourceId): ?string
    {
        $id = self::canonicalId($sourceId);

        return $id !== null && $id === $sourceId
            ? self::BASE_URL.self::LISTING_PATH.'?'.http_build_query(['query' => substr($id, 4)])
            : null;
    }

    /** The IRAS number written one way (IRAS1234567), from "1234567" or "IRAS ID: 1234567". */
    public static function canonicalId(?string $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/^\d{5,7}$/', $value) === 1) {
            $value = 'IRAS'.$value;
        }

        if (preg_match(IdentifierScheme::Iras->pattern(), $value, $matches) !== 1) {
            return null;
        }

        return IdentifierScheme::Iras->format(array_values(array_slice($matches, 1)));
    }
}
