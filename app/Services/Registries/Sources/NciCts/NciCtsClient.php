<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\NciCts;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\FetchedDocument;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;

/**
 * Reads trials from the NCI Clinical Trials Search API v2 with `POST /trials`, whose JSON body
 * takes the field filters and `from` (offset) and `size`, and whose response is
 * `{"total": n, "data": [trial, ...]}` (both confirmed by NCI's own clients and mock data on
 * GitHub: NCIOCPL/clinical-trials-search-app and clinical-trials-listing-app). The page token is
 * the next offset.
 *
 * The key is sent in the X-API-KEY header, never in the URL, so it is not stored with the
 * response. Without a valid key the API answers HTTP 200 with the HTML page of its developer
 * portal: a response that is not JSON fails the run instead of being read as an empty list.
 *
 * The API's filter for records updated since a date is not documented anywhere that could be
 * read, so every run reads every trial (optionally restricted by the configured filters);
 * unchanged trials are skipped by the importer's content hash.
 */
final class NciCtsClient implements StudyRegistryClient
{
    public const string BASE_URL = 'https://clinicaltrialsapi.cancer.gov/api/v2';

    /** Largest page the API serves (third-party documentation, unverified). */
    public const int MAX_PAGE_SIZE = 50;

    public const string KEY_ENV = 'REGISTRY_NCI_CTS_API_KEY';

    /**
     * @param  array<string, mixed>  $filters  field filters added to every search, e.g. ['current_trial_status' => ['Active']]
     */
    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly string $baseUrl = self::BASE_URL,
        private readonly mixed $apiKey = null,
        private readonly array $filters = [],
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        if (! is_string($this->apiKey) || trim($this->apiKey) === '') {
            throw new RegistryRequestFailed('The NCI Clinical Trials Search API needs an API key: set '.self::KEY_ENV.'.');
        }

        $from = self::decodeToken($pageToken);
        $size = max(1, min($pageSize, self::MAX_PAGE_SIZE));

        $document = $this->fetcher->post(
            $this->source,
            rtrim($this->baseUrl, '/').'/trials',
            ['from' => $from, 'size' => $size] + $this->filters,
            ['X-API-KEY' => trim($this->apiKey), 'Accept' => 'application/json'],
        );

        $listing = self::parseTrials($document);
        $next = $from + count($listing['trials']);

        return new StudyPage(
            $listing['trials'],
            $listing['trials'] !== [] && $next < $listing['total'] ? (string) $next : null,
        );
    }

    /**
     * @return array{total: int, trials: list<array<string, mixed>>}
     *
     * @throws RegistryRequestFailed when the response is not a CTS trial list
     */
    public static function parseTrials(FetchedDocument $document): array
    {
        if (! str_contains(strtolower((string) $document->contentType), 'json')) {
            throw new RegistryRequestFailed(sprintf(
                'The NCI Clinical Trials Search API answered with %s instead of JSON; the API key (%s) is probably missing or invalid.',
                $document->contentType ?? 'no content type',
                self::KEY_ENV,
            ));
        }

        $decoded = json_decode($document->body, true);

        if (! is_array($decoded) || ! is_int($decoded['total'] ?? null) || ! is_array($decoded['data'] ?? null) || ! array_is_list($decoded['data'])) {
            throw RegistryRequestFailed::invalidPayload();
        }

        foreach ($decoded['data'] as $trial) {
            if (! is_array($trial) || ! is_string($trial['nci_id'] ?? null) || preg_match(NciCtsSource::ID_PATTERN, $trial['nci_id']) !== 1) {
                throw new RegistryRequestFailed('An NCI Clinical Trials Search result has no valid NCI id; the response format may have changed.');
            }
        }

        return ['total' => $decoded['total'], 'trials' => $decoded['data']];
    }

    public static function decodeToken(?string $token): int
    {
        if ($token === null || $token === '') {
            return 0;
        }

        if (! ctype_digit($token)) {
            throw new RegistryRequestFailed("Invalid NCI Clinical Trials Search page token {$token}.");
        }

        return (int) $token;
    }
}
