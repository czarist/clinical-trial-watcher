<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Isrctn;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * ISRCTN registry, read through its public XML API. Records are identified by their ISRCTN
 * number (ISRCTN followed by eight digits).
 */
final class IsrctnSource implements RegistrySource
{
    public const string KEY = 'isrctn';

    private ?IsrctnClient $client = null;

    public function __construct(private readonly RegistryFetcher $fetcher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'ISRCTN';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Api,
            termsUrl: 'https://www.isrctn.com/page/terms',
            licence: 'Narrative fields (summaries, objectives, interventions, outcomes, results) under CC BY; other content is metadata reusable without attribution (CC0 for records submitted since 2019). Commercial reuse allowed.',
            attribution: 'Source: ISRCTN registry (https://www.isrctn.com), narrative content licensed under CC BY.',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            minIntervalMs: null,
            commercialUse: true,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client ??= new IsrctnClient(
            $this->fetcher,
            $this,
            (string) config('registries.options.isrctn.base_url', 'https://www.isrctn.com'),
            (string) config('registries.options.isrctn.full_import_from', '1990-01-01'),
            (int) config('registries.options.isrctn.max_window_days', 366),
            (int) config('registries.options.isrctn.max_records_per_request', 100),
        );
    }

    public function mapper(): StudyPayloadMapper
    {
        return new IsrctnMapper;
    }

    public function recordUrl(string $sourceId): ?string
    {
        return preg_match('/^ISRCTN\d{8}$/', $sourceId) === 1 ? 'https://www.isrctn.com/'.$sourceId : null;
    }
}
