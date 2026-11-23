<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Umin;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * UMIN Clinical Trials Registry (Japan), read from its daily English CSV dump. Built but
 * dormant: UMIN-CTR's terms forbid commercial use and commercial web services that display
 * its data, so every request is refused until UMIN Center's written permission is recorded
 * in `registries.permissions.umin`.
 */
final class UminSource implements RegistrySource
{
    public const string KEY = 'umin';

    private ?UminClient $client = null;

    public function __construct(private readonly RegistryFetcher $fetcher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'UMIN-CTR';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Download,
            termsUrl: 'https://www.umin.ac.jp/ctr/csvdata.html',
            licence: 'Written permission required. The terms forbid using UMIN-CTR data for marketing, promotional or commercial purposes, building a commercial web service that displays it, and redistributing the dataset; without permission only hyperlinks to UMIN-CTR pages are allowed. Users must credit UMIN-CTR, keep the data current, show the date UMIN-CTR processed it and state modifications. robots.txt of the download host asks for a 10-second crawl delay.',
            attribution: 'Source: UMIN-CTR (UMIN Clinical Trials Registry, https://www.umin.ac.jp/ctr/), data as processed by UMIN-CTR on the date of the last import; fields may be reformatted.',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: (bool) config('registries.permissions.umin', false),
            minIntervalMs: 10_000,
            commercialUse: false,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client ??= new UminClient(
            $this->fetcher,
            $this,
            (string) config('registries.options.umin.dump_url', UminClient::DUMP_URL),
        );
    }

    public function mapper(): StudyPayloadMapper
    {
        return new UminMapper;
    }

    /**
     * UMIN-CTR's record pages are addressed by receipt number (R000000000), not by the UMIN
     * trial number, so no URL can be built from the record id.
     */
    public function recordUrl(string $sourceId): ?string
    {
        return null;
    }
}
