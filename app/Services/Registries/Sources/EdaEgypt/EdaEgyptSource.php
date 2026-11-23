<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\EdaEgypt;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\Parsing\PdfTextExtractor;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * Egyptian Drug Authority: the "Clinical Trials Registry at EDA", a cumulative PDF published
 * about every quarter and linked from a landing page. The registry has no identifier of its
 * own; records are identified by the study code (the protocol number given at submission).
 */
final class EdaEgyptSource implements RegistrySource
{
    public const string KEY = 'eda_egypt';

    private readonly EdaEgyptClient $client;

    private readonly EdaEgyptMapper $mapper;

    public function __construct(RegistryFetcher $fetcher, PdfTextExtractor $extractor)
    {
        $this->client = new EdaEgyptClient($fetcher, $extractor, $this);
        $this->mapper = new EdaEgyptMapper;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'EDA (Egypt)';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Scraping,
            termsUrl: EdaEgyptClient::DEFAULT_LANDING_URL,
            licence: 'No terms of use or licence published for the registry PDFs; reuse rights unconfirmed. Shown with attribution, kept out of paid features.',
            attribution: 'Source: Egyptian Drug Authority, Clinical Trials Registry at EDA',
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

    /** The registry is a PDF without a page per study, so no record URL can be built. */
    public function recordUrl(string $sourceId): ?string
    {
        return null;
    }
}
