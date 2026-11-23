<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Rebec;

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
 * Reads ReBEC's public listing (/list/{n}, 20 trials per page, newest registration first) and
 * the trial page (/rg/RBR-...) of each listed trial.
 *
 * The listing is ordered by registration date and a new revision does not move a trial, so an
 * incremental run cannot stop early: it sweeps every list page (cheap, and answered from the
 * stored copy when unchanged) and fetches only the trials whose last approval date is on or
 * after updatedSince. The page token is "{list page}:{position in that page}".
 */
final readonly class RebecClient implements StudyRegistryClient
{
    /** Labels are read in English; the registry localizes them by Accept-Language. */
    private const array HEADERS = ['Accept-Language' => 'en'];

    public function __construct(
        private RegistryFetcher $fetcher,
        private RegistrySource $source,
    ) {}

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        [$listPage, $position] = self::parseToken($pageToken);
        $document = $this->fetcher->get($this->source, RebecSource::BASE_URL.'/list/'.$listPage, [], self::HEADERS);
        $listing = self::parseListing($document->body, $document->contentType);
        $rows = $listing['rows'];
        $studies = [];

        for ($index = $position, $count = count($rows); $index < $count; $index++) {
            if (count($studies) >= max(1, $pageSize)) {
                return new StudyPage($studies, $listPage.':'.$index);
            }

            if (self::changedSince($rows[$index], $updatedSince)) {
                $studies[] = $this->trial($rows[$index]);
            }
        }

        $hasNext = $rows !== [] && $listPage < $listing['lastPage'];

        return new StudyPage($studies, $hasNext ? ($listPage + 1).':0' : null);
    }

    /**
     * @return array{0: int, 1: int} list page (from 1) and position in it
     */
    public static function parseToken(?string $token): array
    {
        if ($token === null || $token === '') {
            return [1, 0];
        }

        if (preg_match('/^(\d+):(\d+)$/', $token, $matches) !== 1 || (int) $matches[1] < 1) {
            throw new InvalidArgumentException("Invalid ReBEC page token [{$token}].");
        }

        return [(int) $matches[1], (int) $matches[2]];
    }

    /**
     * Rows of a list page and the last page number shown by its pagination.
     *
     * @return array{rows: list<array{id: string, title: ?string, registered_on: ?string, approved_on: ?string, revision: ?int}>, lastPage: int}
     *
     * @throws RegistryRequestFailed when the page is not a ReBEC listing
     */
    public static function parseListing(string $html, ?string $contentType = null): array
    {
        if ($contentType !== null && ! str_contains(strtolower($contentType), 'html')) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $page = new HtmlDocument($html);
        $cards = $page->nodes("//div[contains(@class, 'card-body')][.//a[contains(@class, 'title_link')]]");
        $pagination = $page->nodes("//ul[contains(@class, 'pagination')]");

        if ($cards === [] && $pagination === []) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $rows = [];

        foreach ($cards as $card) {
            $href = (string) $page->text(".//a[contains(@class, 'title_link')]/@href", $card);
            $id = preg_match('#/rg/([^/?\#]+)/?$#', $href, $match) === 1 ? RebecSource::canonicalId($match[1]) : null;

            if ($id === null) {
                continue;
            }

            // Values in order: registration date, last approval date, last revision.
            $dates = [];
            $revision = null;

            foreach ($page->texts(".//span[contains(@class, 'value')]", $card) as $value) {
                if (preg_match('#(\d{2})/(\d{2})/(\d{4})#', $value, $date) === 1) {
                    $dates[] = checkdate((int) $date[1], (int) $date[2], (int) $date[3]) ? "{$date[3]}-{$date[1]}-{$date[2]}" : null;
                } elseif (preg_match('/^\d+$/', $value) === 1) {
                    $revision = (int) $value;
                }
            }

            $rows[] = [
                'id' => $id,
                'title' => $page->text(".//a[contains(@class, 'title_link')]", $card),
                'registered_on' => $dates[0] ?? null,
                'approved_on' => $dates[1] ?? $dates[0] ?? null,
                'revision' => $revision,
            ];
        }

        $pages = array_filter(
            $page->texts("//ul[contains(@class, 'pagination')]//a"),
            static fn (string $text): bool => ctype_digit($text),
        );

        return ['rows' => $rows, 'lastPage' => $pages === [] ? 1 : max(array_map('intval', $pages))];
    }

    /**
     * @param  array{approved_on: ?string}  $row
     */
    private static function changedSince(array $row, ?CarbonInterface $updatedSince): bool
    {
        if ($updatedSince === null || $row['approved_on'] === null) {
            return true;
        }

        return CarbonImmutable::parse($row['approved_on'])->greaterThanOrEqualTo($updatedSince->toImmutable()->startOfDay());
    }

    /**
     * @param  array{id: string, title: ?string, registered_on: ?string, approved_on: ?string, revision: ?int}  $row
     * @return array<string, mixed>
     */
    private function trial(array $row): array
    {
        $url = RebecSource::BASE_URL.'/rg/'.$row['id'];
        $document = $this->fetcher->get($this->source, $url, [], self::HEADERS);
        $page = new HtmlDocument($document->body);

        if ($page->nodes("//div[contains(@class, 'mainContent')] | //script[@type='application/ld+json']") === []) {
            throw new RegistryRequestFailed("The ReBEC page {$url} does not contain a trial record.");
        }

        return [
            'id' => $row['id'],
            'url' => $url,
            'registered_on' => $row['registered_on'],
            'approved_on' => $row['approved_on'],
            'revision' => $row['revision'],
            'html' => RebecMapper::withoutPersonalData($document->body),
        ];
    }
}
