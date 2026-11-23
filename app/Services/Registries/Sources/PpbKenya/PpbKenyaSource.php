<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\PpbKenya;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * The clinical trials registry of Kenya's Pharmacy and Poisons Board (ctr.pharmacyboardkenya.org),
 * read from its public lists of approved, stopped and suspended trials and their record pages.
 *
 * Records are identified by the PPB protocol number (ECCT/YY/MM/NN), the board's own reference,
 * rather than by the internal number in the page address, which a migration of the system can
 * change. A record shown without a protocol number is identified as "view-{number}". The
 * registry publishes no other registry's identifiers, so its records are not linked to their
 * registrations elsewhere.
 */
final readonly class PpbKenyaSource implements RegistrySource
{
    public const string KEY = 'ppb_kenya';

    public const string BASE_URL = 'https://ctr.pharmacyboardkenya.org';

    public function __construct(
        private RegistryFetcher $fetcher,
    ) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Kenya PPB Clinical Trials Registry';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Scraping,
            termsUrl: self::BASE_URL.'/',
            licence: 'No terms of use or licence published ("Copyright © 2026. All Rights Reserved."); the site states that the public can see all PPB-approved trials. robots.txt is empty.',
            attribution: 'Source: Pharmacy and Poisons Board, Kenya – Clinical Trials Registry (ctr.pharmacyboardkenya.org)',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            commercialUse: false,
        );
    }

    public function client(): StudyRegistryClient
    {
        return new PpbKenyaClient($this->fetcher, $this);
    }

    public function mapper(): StudyPayloadMapper
    {
        return new PpbKenyaMapper;
    }

    /**
     * Only records identified by their page number have an address: a protocol number cannot
     * be turned into one without a search.
     */
    public function recordUrl(string $sourceId): ?string
    {
        return preg_match('/^view-([1-9]\d{0,9})$/', $sourceId, $match) === 1 ? self::viewUrl((int) $match[1]) : null;
    }

    public static function viewUrl(int $viewId): string
    {
        return self::BASE_URL.'/applications/view/'.$viewId;
    }
}
