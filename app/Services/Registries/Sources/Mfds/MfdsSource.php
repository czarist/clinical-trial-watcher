<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Mfds;

use App\Enums\AccessMethod;
use App\Services\ClinicalTrials\Import\StudyPayloadMapper;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\SourceAccess;

/**
 * Clinical trial plans approved by the Korean Ministry of Food and Drug Safety (MFDS), read
 * through the data.go.kr "의약품 임상시험 계획 상세정보" API (dataset 15090589). Records are
 * identified by the MFDS trial serial number (CLNC_TEST_SN). The API needs an operator's
 * data.go.kr service key.
 */
final class MfdsSource implements RegistrySource
{
    public const string KEY = 'mfds';

    private ?MfdsClient $client = null;

    public function __construct(private readonly RegistryFetcher $fetcher) {}

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return 'MFDS Korea';
    }

    public function access(): SourceAccess
    {
        return new SourceAccess(
            method: AccessMethod::Api,
            termsUrl: 'https://www.data.go.kr/data/15090589/openapi.do',
            licence: 'Public data with no restriction on the scope of use (이용허락범위 제한 없음); the Public Data Portal policy allows free reuse, including commercial use, without separate permission. A data.go.kr service key is required.',
            attribution: 'Source: Ministry of Food and Drug Safety (MFDS), data.go.kr',
            reviewedOn: '2026-11-23',
            automatedAccessAllowed: true,
            minIntervalMs: null,
            commercialUse: true,
        );
    }

    public function client(): StudyRegistryClient
    {
        return $this->client ??= new MfdsClient(
            $this->fetcher,
            $this,
            (string) config('registries.options.mfds.service_key', ''),
            (string) config('registries.options.mfds.endpoint', MfdsClient::ENDPOINT),
            (int) config('registries.options.mfds.max_rows', 100),
        );
    }

    public function mapper(): StudyPayloadMapper
    {
        return new MfdsMapper;
    }

    /** MFDS publishes no public page per approved trial plan. */
    public function recordUrl(string $sourceId): ?string
    {
        return null;
    }
}
