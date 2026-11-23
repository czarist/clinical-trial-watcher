<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Mfds;

use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\Sources\DataGoKr\DataGoKr;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Reads approved clinical trial plans from the MFDS data.go.kr API, page by page
 * (`pageNo`/`numOfRows`, JSON). The service key travels as a secret query parameter: it is
 * sent but never stored with the page or written to a message.
 *
 * The API filters only by approval date (`clst_aprv_start_dt`/`clst_aprv_end_dt`), so an
 * incremental run reads the plans approved since the last run. Amendments (a newer
 * HIST_REG_DT) and status changes of older plans are only seen by a full run, which pages
 * the whole list; unchanged records are recognised by their content hash.
 *
 * Payload: ['item' => the API item, 'url' => an API query for that trial, without the key].
 */
final class MfdsClient implements StudyRegistryClient
{
    public const string ENDPOINT = 'https://apis.data.go.kr/1471000/ClncExamPlanDtlService03/getClncExamPlanDtlInq03';

    public const string KEY_VARIABLE = 'REGISTRY_MFDS_SERVICE_KEY';

    /** Format of the approval-date filter (unverified against a live response; the portal documents YYYYMMDD dates). */
    private const string FILTER_DATE_FORMAT = 'Ymd';

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly string $serviceKey,
        private readonly string $endpoint = self::ENDPOINT,
        private readonly int $maxRows = 100,
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        $key = DataGoKr::serviceKey($this->serviceKey, 'MFDS', self::KEY_VARIABLE);

        if ($pageToken !== null && $pageToken !== '' && (! ctype_digit($pageToken) || (int) $pageToken < 1)) {
            throw new InvalidArgumentException("Invalid MFDS page token [{$pageToken}].");
        }

        $pageNo = $pageToken !== null && $pageToken !== '' ? (int) $pageToken : 1;
        $rows = max(1, min($pageSize, $this->maxRows));
        $query = ['type' => 'json', 'pageNo' => $pageNo, 'numOfRows' => $rows];

        if ($updatedSince !== null) {
            $query['clst_aprv_start_dt'] = CarbonImmutable::instance($updatedSince)->setTimezone('Asia/Seoul')->format(self::FILTER_DATE_FORMAT);
            $query['clst_aprv_end_dt'] = CarbonImmutable::now('Asia/Seoul')->addDay()->format(self::FILTER_DATE_FORMAT);
        }

        $document = $this->fetcher->get($this->source, $this->endpoint, $query, ['Accept' => 'application/json'], ['serviceKey' => $key]);
        $result = DataGoKr::parse($document->body, 'MFDS', self::KEY_VARIABLE);

        $payloads = array_map(
            fn (array $item): array => ['item' => $item, 'url' => $this->recordApiUrl($item) ?? $document->url],
            $result['items'],
        );

        $next = $result['items'] !== [] && $pageNo * $rows < $result['totalCount'] ? (string) ($pageNo + 1) : null;

        return new StudyPage($payloads, $next);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function recordApiUrl(array $item): ?string
    {
        $id = (new MfdsMapper)->payloadId(['item' => $item]);

        return $id !== null ? $this->endpoint.'?'.http_build_query(['type' => 'json', 'clnc_test_sn' => $id]) : null;
    }
}
