<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Hra;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\Parsing\HtmlDocument;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Reads the HRA research summaries listing (20 summaries per page) and the summary page of
 * each listed study.
 *
 * Incremental runs filter the listing by the date of the REC opinion (`date_from`, dd/mm/yyyy).
 * Summaries are published weeks after the opinion, so the filter starts LOOKBACK_DAYS before
 * updatedSince; studies seen again are matched by IRAS number. The page token is
 * "{listing page}:{position in that page}".
 */
final readonly class HraClient implements StudyRegistryClient
{
    public const int LOOKBACK_DAYS = 120;

    public function __construct(
        private RegistryFetcher $fetcher,
        private RegistrySource $source,
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        [$listPage, $position] = self::parseToken($pageToken);
        $document = $this->fetcher->get($this->source, HraSource::BASE_URL.HraSource::LISTING_PATH, self::listingQuery($listPage, $updatedSince));
        $listing = self::parseListing($document->body, $document->contentType);
        $rows = $listing['rows'];
        $studies = [];

        for ($index = $position, $count = count($rows); $index < $count; $index++) {
            if (count($studies) >= max(1, $pageSize)) {
                return new StudyPage($studies, $listPage.':'.$index);
            }

            $studies[] = $this->summary($rows[$index]);
        }

        $hasNext = $rows !== [] && $listPage < $listing['lastPage'];

        return new StudyPage($studies, $hasNext ? ($listPage + 1).':0' : null);
    }

    /**
     * @return array<string, string|int>
     */
    public static function listingQuery(int $page, ?CarbonInterface $updatedSince): array
    {
        $query = ['page' => $page];

        if ($updatedSince !== null) {
            $query['date_from'] = $updatedSince->toImmutable()->subDays(self::LOOKBACK_DAYS)->format('d/m/Y');
        }

        return $query;
    }

    /**
     * @return array{0: int, 1: int} listing page (from 1) and position in it
     */
    public static function parseToken(?string $token): array
    {
        if ($token === null || $token === '') {
            return [1, 0];
        }

        if (preg_match('/^(\d+):(\d+)$/', $token, $matches) !== 1 || (int) $matches[1] < 1) {
            throw new InvalidArgumentException("Invalid HRA page token [{$token}].");
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * Rows of a listing page and the last page number shown by its pagination.
     *
     * @return array{rows: list<array{path: string, id: ?string, title: ?string, rec_opinion_date: ?string, rec_reference: ?string}>, lastPage: int}
     *
     * @throws RegistryRequestFailed when the page is not an HRA research summaries listing
     */
    public static function parseListing(string $html, ?string $contentType = null): array
    {
        if ($contentType !== null && ! str_contains(strtolower($contentType), 'html')) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $page = new HtmlDocument($html);

        if ($page->nodes("//ul[contains(concat(' ', normalize-space(@class), ' '), ' results-listing ')]") === []) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $rows = [];

        foreach ($page->nodes("//li[contains(@class, 'results-listing__item')]/a[@href]") as $link) {
            $path = (string) $page->text('./@href', $link);

            // Only summary pages under the listing path; never follow links elsewhere.
            if (preg_match('#^'.preg_quote(HraSource::LISTING_PATH, '#').'[a-z0-9-]+/$#', $path) !== 1) {
                continue;
            }

            $rows[] = [
                'path' => $path,
                'id' => HraSource::canonicalId($page->text(".//li[contains(@class, 'category')][starts-with(normalize-space(.), 'IRAS ID')]/span", $link)),
                'title' => $page->text(".//div[contains(@class, 'results-listing__item-heading')]", $link),
                'rec_opinion_date' => self::date($page->text(".//li[contains(@class, 'date')]", $link)),
                'rec_reference' => $page->text(".//li[contains(@class, 'category')][starts-with(normalize-space(.), 'REC reference')]/span", $link),
            ];
        }

        $pages = [];

        foreach ($page->nodes("//ul[contains(@class, 'pagination')]//a/@href") as $href) {
            if (preg_match('/[?&]page=(\d+)/', (string) $href->nodeValue, $match) === 1) {
                $pages[] = (int) $match[1];
            }
        }

        return ['rows' => $rows, 'lastPage' => $pages === [] ? 1 : max($pages)];
    }

    /** "12 Aug 2026" as 2026-08-12. */
    private static function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!j M Y', $value);

        return $date !== null && $date->format('j M Y') === $value ? $date->toDateString() : null;
    }

    /**
     * @param  array{path: string, id: ?string, title: ?string, rec_opinion_date: ?string, rec_reference: ?string}  $row
     * @return array<string, mixed>
     */
    private function summary(array $row): array
    {
        $url = HraSource::BASE_URL.$row['path'];
        $document = $this->fetcher->get($this->source, $url);
        $page = new HtmlDocument($document->body);

        if ($page->nodes("//div[contains(@class, 'research-summary-list')]/h1") === []) {
            throw new RegistryRequestFailed("The HRA page {$url} does not contain a research summary.");
        }

        return [
            'id' => $row['id'],
            'url' => $url,
            'rec_opinion_date' => $row['rec_opinion_date'],
            'html' => HraMapper::withoutPersonalData($document->body),
        ];
    }
}
