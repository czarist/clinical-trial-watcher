<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Sukl;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * SÚKL clinical trials database (Czechia, prehledy.sukl.cz): the trials SÚKL authorised under
 * the former Clinical Trials Directive, up to 31 January 2023 (newer Czech trials are in CTIS).
 * Records are identified by their EudraCT number, also kept as a secondary identifier so a
 * SÚKL record links to the EU CTR and CTIS records of the same trial.
 *
 * Dormant: SÚKL's terms require prior consent for reuse and exclude bulk downloading, so
 * requests are refused until `registries.permissions.sukl` records written permission.
 */
final class SuklSource implements RegistrySource
{
    public const string KEY = 'sukl';

    public const string ID_PATTERN = '/^(?:19|20)\d{2}-\d{6}-\d{2}$/';

    private ?SuklClient $client = null;

    public function __construct(private readonly RegistryFetcher $fetcher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'SÚKL';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Scraping,
            termsUrl: 'https://sukl.gov.cz/podminky-vyuzivani-sluzeb-webove-prezentace/',
            licence: 'Written permission required: SÚKL\'s website terms require the operator\'s prior consent to take over or redistribute any published element, and state that the content is not intended for bulk downloading of files or data. The SÚKL open-data licence (commercial use with attribution) does not cover this database. robots.txt of prehledy.sukl.cz could not be read (timeout).',
            attribution: 'Zdroj: Státní ústav pro kontrolu léčiv (SÚKL), Databáze klinických hodnocení (prehledy.sukl.cz), použito se souhlasem SÚKL.',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: (bool) config('registries.permissions.sukl', false),
            minIntervalMs: 5000,
            commercialUse: false,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client ??= new SuklClient(
            $this->fetcher,
            $this,
            (string) config('registries.options.sukl.base_url', SuklClient::BASE_URL),
        );
    }

    public function mapper(): StudyPayloadMapper
    {
        return new SuklMapper;
    }

    public function recordUrl(string $sourceId): ?string
    {
        // The database is a single-page application without record pages; its English detail
        // document is the only per-record address.
        return preg_match(self::ID_PATTERN, $sourceId) === 1
            ? SuklClient::BASE_URL.'/detail/'.$sourceId.'/jazyk/en'
            : null;
    }
}
