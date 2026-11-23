<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\HealthCanada;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Reads the Health Canada Clinical Trials Database API.
 *
 * The API has no search, paging or updated-since parameter and no last-modified field: every
 * run downloads the full protocol list and the drug product list (about 2 MB together),
 * joins the drug products (brand and manufacturer) to their protocol, and pages through the
 * result with an offset token. Unchanged records are recognised by their content hash. The
 * lists are read once per run (on the first page) and kept for the following pages.
 *
 * Payload: ['protocol' => the protocol object, 'drugProducts' => its drug product rows, 'url' => the record's API URL].
 */
final class HealthCanadaClient implements StudyRegistryClient
{
    /** @var list<array<string, mixed>>|null */
    private ?array $protocols = null;

    /** @var array<int, list<array<string, mixed>>> drug product rows keyed by protocol id */
    private array $drugProducts = [];

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly string $baseUrl = 'https://health-products.canada.ca/api/clinical-trial',
        private readonly string $lang = 'en',
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        if ($pageToken !== null && $pageToken !== '' && ! ctype_digit($pageToken)) {
            throw new InvalidArgumentException("Invalid Health Canada page token [{$pageToken}].");
        }

        $offset = (int) $pageToken;

        if ($offset === 0 || $this->protocols === null) {
            $this->load();
        }

        $pageSize = max(1, $pageSize);
        $protocols = $this->protocols ?? [];
        $payloads = array_map(fn (array $protocol): array => [
            'protocol' => $protocol,
            'drugProducts' => is_int($protocol['protocol_id'] ?? null) ? ($this->drugProducts[$protocol['protocol_id']] ?? []) : [],
            'url' => is_int($protocol['protocol_id'] ?? null) ? $this->recordApiUrl($protocol['protocol_id']) : null,
        ], array_slice($protocols, $offset, $pageSize));

        $next = $offset + $pageSize < count($protocols) ? (string) ($offset + $pageSize) : null;

        return new StudyPage($payloads, $next);
    }

    /**
     * Protocol objects of the protocol list response.
     *
     * @return list<array<string, mixed>>
     *
     * @throws RegistryRequestFailed when the body is not a protocol list
     */
    public static function parseProtocols(string $json): array
    {
        $rows = self::decodeList($json);

        foreach ($rows as $row) {
            if (! array_key_exists('protocol_id', $row) || ! array_key_exists('protocol_title', $row)) {
                throw RegistryRequestFailed::invalidPayload();
            }
        }

        return $rows;
    }

    /**
     * Drug product rows keyed by protocol id, sorted by brand id. Rows with a zero id (the API's
     * empty object, returned for instance when `?id=` is given a protocol id) are ignored.
     *
     * @return array<int, list<array<string, mixed>>>
     *
     * @throws RegistryRequestFailed when the body is not a drug product list
     */
    public static function parseDrugProducts(string $json): array
    {
        $byProtocol = [];

        foreach (self::decodeList($json) as $row) {
            if (! array_key_exists('protocol_id', $row) || ! array_key_exists('brand_id', $row)) {
                throw RegistryRequestFailed::invalidPayload();
            }

            if (! is_int($row['protocol_id']) || $row['protocol_id'] < 1 || ! is_int($row['brand_id']) || $row['brand_id'] < 1) {
                continue;
            }

            $byProtocol[$row['protocol_id']][] = [
                'brand_id' => $row['brand_id'],
                'brand_name' => $row['brand_name'] ?? null,
                'manufacturer_id' => $row['manufacturer_id'] ?? null,
                'manufacturer_name' => $row['manufacturer_name'] ?? null,
            ];
        }

        foreach ($byProtocol as &$rows) {
            usort($rows, fn (array $a, array $b): int => $a['brand_id'] <=> $b['brand_id']);
        }

        unset($rows);

        return $byProtocol;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decodeList(string $json): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw RegistryRequestFailed::invalidPayload();
        }

        if (! is_array($data) || ! array_is_list($data)) {
            throw RegistryRequestFailed::invalidPayload();
        }

        return array_values(array_filter($data, 'is_array'));
    }

    private function load(): void
    {
        $this->protocols = self::parseProtocols($this->get('protocol/'));
        $this->drugProducts = self::parseDrugProducts($this->get('drugproduct/'));
    }

    private function get(string $endpoint): string
    {
        $document = $this->fetcher->get(
            $this->source,
            rtrim($this->baseUrl, '/').'/'.$endpoint,
            ['lang' => $this->lang, 'type' => 'json'],
            ['Accept' => 'application/json'],
        );

        if ($document->contentType !== null && ! str_contains(strtolower($document->contentType), 'json')) {
            throw RegistryRequestFailed::invalidPayload();
        }

        return $document->body;
    }

    private function recordApiUrl(int $protocolId): string
    {
        return rtrim($this->baseUrl, '/').'/protocol/?'.http_build_query(['id' => $protocolId, 'lang' => $this->lang, 'type' => 'json']);
    }
}
