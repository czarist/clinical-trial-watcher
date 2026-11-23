<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Orphanet;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * Orphanet's rare-disease clinical trials, read from the Orphadata "National Clinical Trials"
 * or "Multinational Clinical Trials" XML dump delivered under a contract with Orphanet-AISBL.
 * Records are identified by Orphanet's trial id; most mirror ClinicalTrials.gov or CTIS
 * trials, which their NCT and EU CT numbers link to, and add ORPHAcode annotations.
 *
 * Dormant: the dataset is not openly licensed, so requests are refused until
 * `registries.permissions.orphanet` records the signed contract.
 */
final class OrphanetSource implements RegistrySource
{
    public const string KEY = 'orphanet';

    private ?OrphanetClient $client = null;

    public function __construct(private readonly RegistryFetcher $fetcher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'Orphanet';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Download,
            termsUrl: 'https://www.orphadata.com/legal-notice/',
            licence: 'Written permission required: the clinical trials datasets are not under CC BY 4.0; they are delivered on request under a Data Transfer Agreement (academia) or a service contract with Orphanet-AISBL (for-profit). The legal notice forbids copying, reproducing, exploiting or redistributing the database without prior written consent from INSERM US14 - Orphanet. The contract must allow public display and AI-generated digests before those uses.',
            attribution: 'Source: Orphanet (www.orpha.net), INSERM US14, clinical trials dataset delivered by Orphadata (www.orphadata.com) under contract with Orphanet-AISBL. Results based on these data may not be a true reflection of the Orphanet data.',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: (bool) config('registries.permissions.orphanet', false),
            minIntervalMs: null,
            commercialUse: false,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client ??= new OrphanetClient(
            $this->fetcher,
            $this,
            (string) config('registries.options.orphanet.dump_url', ''),
        );
    }

    public function mapper(): StudyPayloadMapper
    {
        return new OrphanetMapper;
    }

    public function recordUrl(string $sourceId): ?string
    {
        // Orphanet's public pages are addressed by the expert-resource number, not the trial
        // id, so no page can be built from the record's identifier.
        return null;
    }
}
