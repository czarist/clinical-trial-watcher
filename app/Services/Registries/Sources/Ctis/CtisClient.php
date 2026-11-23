<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Ctis;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\FetchedDocument;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Reads CTIS trials through the portal's search and retrieve endpoints.
 *
 * The search (POST, JSON body) lists trials sorted by their last update, newest first; each
 * listed trial is then read in full from the retrieve endpoint. The search has no "updated
 * since" filter: an incremental run stops at the first trial last updated before the given
 * date, less one day because the search gives the date to the day only. The page token is
 * the next search page and its size, so the pages stay aligned within a run. Trials updated
 * while a run is paging move to the top of the list and may be skipped or read twice; the
 * next run picks them up.
 */
final class CtisClient implements StudyRegistryClient
{
    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly string $baseUrl = 'https://euclinicaltrials.eu/ctis-public-api',
        private readonly int $maxPageSize = 50,
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        [$page, $size] = $pageToken !== null && $pageToken !== ''
            ? self::decodeToken($pageToken)
            : [1, max(1, min($pageSize, $this->maxPageSize))];

        $document = $this->fetcher->post($this->source, $this->url('search'), self::searchBody($page, $size));
        $listing = self::parseSearch($document->body);
        $oldest = $updatedSince !== null ? CarbonImmutable::parse($updatedSince->format('Y-m-d'))->subDay() : null;
        $studies = [];

        foreach ($listing['rows'] as $row) {
            $lastUpdated = self::searchDate($row['lastUpdated'] ?? null);

            if ($oldest !== null && $lastUpdated !== null && $lastUpdated->lessThan($oldest)) {
                return new StudyPage($studies, null);
            }

            $trial = $this->retrieve((string) $row['ctNumber']);

            if ($trial !== null) {
                $studies[] = $trial + ['searchResult' => $row];
            }
        }

        return new StudyPage($studies, $listing['nextPage'] && $listing['rows'] !== [] ? self::encodeToken($page + 1, $size) : null);
    }

    /**
     * The JSON body of a search request: every trial, most recently updated first.
     *
     * @return array<string, mixed>
     */
    public static function searchBody(int $page, int $size): array
    {
        return [
            'pagination' => ['page' => $page, 'size' => $size],
            'sort' => ['property' => 'lastUpdated', 'direction' => 'DESC'],
            'searchCriteria' => (object) [],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, nextPage: bool}
     *
     * @throws RegistryRequestFailed when the body is not a CTIS search result
     */
    public static function parseSearch(string $body): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded) || ! is_array($decoded['data'] ?? null) || ! array_is_list($decoded['data'])
            || ! is_array($decoded['pagination'] ?? null)) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $rows = [];

        foreach ($decoded['data'] as $row) {
            if (! is_array($row) || ! is_string($row['ctNumber'] ?? null) || preg_match(CtisSource::ID_PATTERN, $row['ctNumber']) !== 1) {
                throw new RegistryRequestFailed('A CTIS search result has no valid EU CT number; the response format may have changed.');
            }

            $rows[] = $row;
        }

        return ['rows' => $rows, 'nextPage' => ($decoded['pagination']['nextPage'] ?? false) === true];
    }

    /**
     * A full trial from the retrieve endpoint, or null when CTIS no longer publishes it (404).
     *
     * @return array<string, mixed>|null
     */
    private function retrieve(string $ctNumber): ?array
    {
        try {
            $document = $this->fetcher->get($this->source, $this->url('retrieve/'.$ctNumber), [], ['Accept' => 'application/json']);
        } catch (RegistryRequestFailed $exception) {
            if ($exception->getCode() === 404) {
                return null;
            }

            throw $exception;
        }

        return self::parseTrial($document, $ctNumber);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RegistryRequestFailed when the body is not the requested CTIS trial
     */
    public static function parseTrial(FetchedDocument $document, string $ctNumber): array
    {
        $trial = json_decode($document->body, true);

        if (! is_array($trial) || ($trial['ctNumber'] ?? null) !== $ctNumber || ! is_array($trial['authorizedApplication'] ?? null)) {
            throw new RegistryRequestFailed("The CTIS record of {$ctNumber} is not a trial document; the response format may have changed.");
        }

        return $trial;
    }

    /** A dd/mm/yyyy date of the search results. */
    public static function searchDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!d/m/Y', $value);

        return $date instanceof CarbonImmutable ? $date : null;
    }

    /**
     * @return array{0: int, 1: int} the search page and its size
     */
    public static function decodeToken(string $token): array
    {
        if (preg_match('/^(\d+):(\d+)$/', $token, $match) !== 1 || (int) $match[1] < 1 || (int) $match[2] < 1) {
            throw new InvalidArgumentException("Invalid CTIS page token [{$token}].");
        }

        return [(int) $match[1], (int) $match[2]];
    }

    public static function encodeToken(int $page, int $size): string
    {
        return $page.':'.$size;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.$path;
    }
}
