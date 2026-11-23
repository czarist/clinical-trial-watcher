<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Cris;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * CRIS, the Korean Clinical Research Information Service (KDCA), read through its data.go.kr
 * API (dataset 3033869). Records are identified by their KCT number. The data is licensed
 * under KOGL Type 2 (attribution, no commercial use): records are shown for free with
 * attribution and kept out of AI digests. The CRIS website disallows crawlers, so only the
 * API host is used.
 */
final class CrisSource implements RegistrySource
{
    public const string KEY = 'cris';

    private ?CrisClient $client = null;

    public function __construct(private readonly RegistryFetcher $fetcher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'CRIS';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Api,
            termsUrl: 'https://www.data.go.kr/data/3033869/openapi.do',
            licence: 'Korea Open Government Licence Type 2 (https://www.kogl.or.kr/info/licenseType2.do): attribution required, no commercial use; the dataset may include third-party rights. A data.go.kr service key is required.',
            attribution: 'Source: Clinical Research Information Service (CRIS), National Institute of Health, Korea Disease Control and Prevention Agency (질병관리청 국립보건연구원 임상연구정보서비스), via data.go.kr, under KOGL Type 2.',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            minIntervalMs: null,
            commercialUse: false,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client ??= new CrisClient(
            $this->fetcher,
            $this,
            (string) config('registries.options.cris.service_key', ''),
            (string) config('registries.options.cris.base_url', CrisClient::BASE_URL),
        );
    }

    public function mapper(): StudyPayloadMapper
    {
        return new CrisMapper;
    }

    /** CRIS record pages use an internal sequence number, not the KCT number, so none is built. */
    public function recordUrl(string $sourceId): ?string
    {
        return null;
    }
}
