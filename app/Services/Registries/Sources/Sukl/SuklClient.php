<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Sukl;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Reads the SÚKL clinical trials database through the JSON backend of its web application:
 * the search (`POST vyhledej`, a JSON body with `pocet` rows per page and page `stranka`)
 * lists EudraCT numbers, and each listed trial is read in full from `detail/{EudraCT}/jazyk/en`.
 *
 * The page token is the next page number and the page size it was computed with. The database
 * is frozen (trials up to 31 January 2023) and has no per-record update date, so incremental
 * runs are not supported: `updatedSince` is ignored and every run reads the whole list.
 */
final class SuklClient implements StudyRegistryClient
{
    public const string BASE_URL = 'https://prehledy.sukl.cz/pkh';

    /** Largest page asked from the search; the backend's own limit is not documented. */
    private const int MAX_PAGE_SIZE = 100;

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly string $baseUrl = self::BASE_URL,
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        [$page, $size] = $pageToken !== null && $pageToken !== ''
            ? self::decodeToken($pageToken)
            : [1, max(1, min($pageSize, self::MAX_PAGE_SIZE))];

        // The backend validates the body strictly: extra keys are answered with HTTP 400.
        $listing = self::parseListing($this->fetcher->post($this->source, $this->url('vyhledej'), [
            'filtr' => '',
            'stav' => 'all',
            'jazyk' => 'en',
            'pocet' => $size,
            'stranka' => $page,
            'sort' => ['nazev'],
            'smer' => 'asc',
        ], ['Accept' => 'application/json'])->body);

        $studies = array_map(fn (string $eudract): array => $this->detail($eudract), $listing['ids']);
        $hasMore = $listing['ids'] !== [] && $page * $size < $listing['total'];

        return new StudyPage($studies, $hasMore ? self::encodeToken($page + 1, $size) : null);
    }

    /**
     * The EudraCT numbers of a search response, in order and without repeats, and the total.
     *
     * @return array{ids: list<string>, total: int}
     *
     * @throws RegistryRequestFailed when the body is not a SÚKL search result
     */
    public static function parseListing(string $body): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! is_array($decoded['data'] ?? null) || ! array_is_list($decoded['data']) || ! is_int($decoded['celkem'] ?? null)) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $ids = [];

        foreach ($decoded['data'] as $row) {
            $id = is_array($row) && is_string($row['eudract'] ?? null) ? trim($row['eudract']) : '';

            if (preg_match(SuklSource::ID_PATTERN, $id) !== 1) {
                throw new RegistryRequestFailed("A SÚKL listed trial has no valid EudraCT number [{$id}]; the response format may have changed.");
            }

            $ids[$id] = $id;
        }

        return ['ids' => array_values($ids), 'total' => $decoded['celkem']];
    }

    /**
     * @return array{0: int, 1: int} the page number and the page size
     */
    public static function decodeToken(string $token): array
    {
        if (preg_match('/^(\d+)\|(\d+)$/', $token, $match) !== 1 || (int) $match[1] < 2 || (int) $match[2] < 1) {
            throw new InvalidArgumentException("Invalid SÚKL page token [{$token}].");
        }

        return [(int) $match[1], (int) $match[2]];
    }

    public static function encodeToken(int $page, int $size): string
    {
        return $page.'|'.$size;
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(string $eudract): array
    {
        $detail = json_decode($this->fetcher->get($this->source, $this->url('detail/'.$eudract.'/jazyk/en'), [], ['Accept' => 'application/json'])->body, true);

        if (! is_array($detail) || ! is_string($detail['eudract'] ?? null) || trim($detail['eudract']) !== $eudract) {
            throw new RegistryRequestFailed("The SÚKL detail of {$eudract} is not a trial document; the response format may have changed.");
        }

        return $detail;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.$path;
    }
}
