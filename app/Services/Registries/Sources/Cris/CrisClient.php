<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Cris;

use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use App\Services\Registries\Sources\DataGoKr\DataGoKr;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Reads CRIS through its data.go.kr API: the list operation (`/list`, at most 50 rows per
 * page) gives every trial with its last-update date, and the detail operation
 * (`/detail?crisNumber=`) the full record. The service key travels as a secret query
 * parameter, never stored with the page or written to a message.
 *
 * The API has no date filter: every run pages the whole list (cheap), and an incremental run
 * fetches details only for trials whose `date_updated` (or, without it, registration date)
 * is on or after the updated-since day. A trial whose detail returns no data keeps its list
 * item as its record.
 *
 * Payload: ['records' => the detail rows of the trial (one per row the API returns), 'url' => the detail URL without the key].
 */
final class CrisClient implements StudyRegistryClient
{
    public const string BASE_URL = 'https://apis.data.go.kr/1352159/crisinfodataview';

    public const string KEY_VARIABLE = 'REGISTRY_CRIS_SERVICE_KEY';

    public const int MAX_ROWS = 50;

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly string $serviceKey,
        private readonly string $baseUrl = self::BASE_URL,
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        $key = DataGoKr::serviceKey($this->serviceKey, 'CRIS', self::KEY_VARIABLE);

        if ($pageToken !== null && $pageToken !== '' && (! ctype_digit($pageToken) || (int) $pageToken < 1)) {
            throw new InvalidArgumentException("Invalid CRIS page token [{$pageToken}].");
        }

        $pageNo = $pageToken !== null && $pageToken !== '' ? (int) $pageToken : 1;
        $rows = max(1, min($pageSize, self::MAX_ROWS));

        $document = $this->fetcher->get(
            $this->source,
            rtrim($this->baseUrl, '/').'/list',
            ['resultType' => 'JSON', 'pageNo' => $pageNo, 'numOfRows' => $rows],
            ['Accept' => 'application/json'],
            ['serviceKey' => $key],
        );
        $list = DataGoKr::parse($document->body, 'CRIS', self::KEY_VARIABLE);
        $since = $updatedSince !== null ? CarbonImmutable::instance($updatedSince)->setTimezone('Asia/Seoul')->format('Y-m-d') : null;
        $payloads = [];

        foreach ($list['items'] as $item) {
            $id = CrisMapper::normalizeId($item['trial_id'] ?? null);

            if ($id === null) {
                // Passed on so the import records the invalid payload instead of dropping it.
                $payloads[] = ['records' => [$item], 'url' => $document->url];

                continue;
            }

            if ($since !== null && ! self::changedSince($item, $since)) {
                continue;
            }

            $payloads[] = $this->detail($id, $item, $key);
        }

        $next = $list['items'] !== [] && $pageNo * $rows < $list['totalCount'] ? (string) ($pageNo + 1) : null;

        return new StudyPage($payloads, $next);
    }

    /**
     * Whether a list item was updated (or, without an update date, registered) on or after the given day.
     * An item without a readable date is treated as changed.
     *
     * @param  array<string, mixed>  $item
     */
    public static function changedSince(array $item, string $sinceDay): bool
    {
        $date = $item['date_updated'] ?? null;

        if (! is_string($date) || trim($date) === '') {
            $date = $item['date_registration'] ?? null;
        }

        if (! is_string($date) || preg_match('/^(\d{4})[-.\/]?(\d{2})[-.\/]?(\d{2})/', trim($date), $match) !== 1) {
            return true;
        }

        return "{$match[1]}-{$match[2]}-{$match[3]}" >= $sinceDay;
    }

    /**
     * @param  array<string, mixed>  $listItem
     * @return array{records: list<array<string, mixed>>, url: string}
     */
    private function detail(string $id, array $listItem, string $key): array
    {
        $url = rtrim($this->baseUrl, '/').'/detail';
        $query = ['resultType' => 'JSON', 'crisNumber' => $id];
        $document = $this->fetcher->get($this->source, $url, $query, ['Accept' => 'application/json'], ['serviceKey' => $key]);
        $detail = DataGoKr::parse($document->body, 'CRIS', self::KEY_VARIABLE);
        $records = $detail['items'];

        if ($records === [] && array_key_exists('trial_id', $detail['body'])) {
            $records = [$detail['body']];
        }

        // Rows of another trial are never mixed into this one.
        $records = array_values(array_filter(
            $records,
            static fn (array $record): bool => ! array_key_exists('trial_id', $record) || CrisMapper::normalizeId($record['trial_id']) === $id,
        ));

        return ['records' => $records === [] ? [$listItem] : $records, 'url' => $document->url];
    }
}
