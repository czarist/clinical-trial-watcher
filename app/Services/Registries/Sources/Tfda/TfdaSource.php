<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Tfda;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * Taiwan Food and Drug Administration: the monthly open-data export of drug clinical trials
 * (dataset 205, a ZIP holding one UTF-8 CSV). Records are identified by the TFDA document
 * number (TFDA收文號); the export has no public page per record.
 */
final class TfdaSource implements RegistrySource
{
    public const string KEY = 'tfda';

    private readonly TfdaClient $client;

    private readonly TfdaMapper $mapper;

    public function __construct(RegistryFetcher $fetcher)
    {
        $this->client = new TfdaClient($fetcher, $this);
        $this->mapper = new TfdaMapper;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'TFDA (Taiwan)';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Download,
            termsUrl: 'https://data.gov.tw/license',
            licence: 'Open Government Data License, version 1: reuse for any purpose, including commercial use, redistribution and adaptation, on condition of attribution.',
            attribution: 'Taiwan Food and Drug Administration (衛生福利部食品藥物管理署), 台灣藥品臨床試驗現況 (Drug Clinical Trial Status, open data 205). '
                .'The Open Data is made available to the public under the Open Government Data License, User can make use of it when complying to the condition and obligation of its terms. '
                .'Open Government Data License: https://data.gov.tw/license',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
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

    /** The export has no public page per trial, so no record URL can be built. */
    public function recordUrl(string $sourceId): ?string
    {
        return null;
    }
}
