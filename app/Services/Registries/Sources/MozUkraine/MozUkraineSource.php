<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\MozUkraine;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\Parsing\PdfTextExtractor;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * Ministry of Health of Ukraine: orders authorising clinical trials ("Про проведення
 * клінічних випробувань лікарських засобів"), whose annex PDF describes each authorised trial.
 * The orders carry no registry identifier; records are identified by the trial's protocol code
 * as written in the annex.
 */
final class MozUkraineSource implements RegistrySource
{
    public const string KEY = 'moz_ukraine';

    private readonly MozUkraineClient $client;

    private readonly MozUkraineMapper $mapper;

    public function __construct(RegistryFetcher $fetcher, PdfTextExtractor $extractor)
    {
        $this->client = new MozUkraineClient($fetcher, $extractor, $this);
        $this->mapper = new MozUkraineMapper;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'MOZ Ukraine';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Scraping,
            termsUrl: 'https://moz.gov.ua/uk/nakazi-moz',
            licence: 'No terms of use published for moz.gov.ua documents. Official documents (orders and their annexes) are likely outside copyright under Ukrainian law, but this is unverified; shown with attribution, kept out of paid features.',
            attribution: 'Source: Наказ МОЗ України (Ministry of Health of Ukraine order) and its annex, moz.gov.ua',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            commercialUse: false,
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

    /** Orders have no page per trial, and their URLs cannot be built from a protocol code. */
    public function recordUrl(string $sourceId): ?string
    {
        return null;
    }
}
