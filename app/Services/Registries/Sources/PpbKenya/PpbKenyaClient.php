<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\PpbKenya;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\Parsing\HtmlDocument;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;
use DOMNode;
use InvalidArgumentException;

/**
 * Reads the PPB lists of trials by status (10 per page, newest approval first) and the record
 * page of each listed trial. Sites come from the list, which is the only place they are shown;
 * investigator names next to them are personal data and are never read.
 *
 * Incremental runs stop reading the approved list at the first trial approved before
 * updatedSince, provided the page is in date order. The stopped and suspended lists are always
 * read in full: a change of status does not change the approval date. The page token is
 * "{status index}:{list page}:{position in that page}".
 */
final readonly class PpbKenyaClient implements StudyRegistryClient
{
    public const array STATUSES = ['approved', 'stopped', 'suspended'];

    /** @var list<string> */
    private array $statuses;

    public function __construct(
        private RegistryFetcher $fetcher,
        private RegistrySource $source,
    ) {
        $configured = (array) config('registries.options.ppb_kenya.statuses', self::STATUSES);
        $this->statuses = array_values(array_intersect(self::STATUSES, $configured));
    }

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        [$statusIndex, $listPage, $position] = self::parseToken($pageToken);
        $status = $this->statuses[$statusIndex] ?? null;

        if ($status === null) {
            return new StudyPage([], null);
        }

        $document = $this->fetcher->get($this->source, self::listUrl($status, $listPage));
        $listing = self::parseListing($document->body, $document->contentType);
        $rows = $listing['rows'];
        $since = $updatedSince?->toDateString();
        $stopAtOlder = $since !== null && $status === 'approved' && self::inDateOrder($rows);
        $studies = [];
        $reachedOlder = false;

        for ($index = $position, $count = count($rows); $index < $count; $index++) {
            $row = $rows[$index];
            $isOlder = $since !== null && $status === 'approved' && $row['approved_on'] !== null && $row['approved_on'] < $since;

            if ($isOlder && $stopAtOlder) {
                $reachedOlder = true;

                break;
            }

            if (count($studies) >= max(1, $pageSize)) {
                return new StudyPage($studies, "{$statusIndex}:{$listPage}:{$index}");
            }

            if (! $isOlder) {
                $studies[] = $this->record($row, $status);
            }
        }

        if (! $reachedOlder && $rows !== [] && $listPage < $listing['pages']) {
            return new StudyPage($studies, $statusIndex.':'.($listPage + 1).':0');
        }

        return new StudyPage($studies, isset($this->statuses[$statusIndex + 1]) ? ($statusIndex + 1).':1:0' : null);
    }

    public static function listUrl(string $status, int $page): string
    {
        return PpbKenyaSource::BASE_URL."/applications/index/sort:approval_date/direction:desc/status:{$status}/page:{$page}";
    }

    /**
     * @return array{0: int, 1: int, 2: int} status index, list page (from 1) and position in it
     */
    public static function parseToken(?string $token): array
    {
        if ($token === null || $token === '') {
            return [0, 1, 0];
        }

        if (preg_match('/^(\d+):(\d+):(\d+)$/', $token, $matches) !== 1 || (int) $matches[2] < 1) {
            throw new InvalidArgumentException("Invalid PPB Kenya page token [{$token}].");
        }

        return [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
    }

    /**
     * Rows of a list page and its position in the list ("Page 1 of 59").
     *
     * @return array{rows: list<array{view_id: int, protocol_no: ?string, code: ?string, title: ?string, approved_on: ?string, sites: list<array{name: string, county: ?string}>}>, page: int, pages: int}
     *
     * @throws RegistryRequestFailed when the page is not a list of applications
     */
    public static function parseListing(string $html, ?string $contentType = null): array
    {
        if ($contentType !== null && ! str_contains(strtolower($contentType), 'html')) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $page = new HtmlDocument($html);
        $table = $page->nodes("//table[.//th[contains(normalize-space(.), 'Protocol No')] and .//th[contains(normalize-space(.), 'Date Approved')]]")[0] ?? null;
        $counter = $page->texts("//p[contains(normalize-space(.), 'Page') and span[contains(@class, 'badge')]]/span[contains(@class, 'badge')]");

        if ($table === null || count($counter) < 2) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $rows = [];

        foreach ($page->nodes('.//tbody/tr[td]', $table) as $tr) {
            $cells = $page->nodes('./td', $tr);
            $href = (string) $page->text('.//a/@href', $cells[1] ?? $tr);

            if (count($cells) < 5 || preg_match('#/applications/view/([1-9]\d{0,9})$#', $href, $match) !== 1) {
                continue;
            }

            $code = $page->text('./b', $cells[2]);
            $title = (string) $page->text('.', $cells[2]);

            if ($code !== null && str_starts_with($title, $code)) {
                $title = trim(substr($title, strlen($code)));
            }

            $rows[] = [
                'view_id' => (int) $match[1],
                'protocol_no' => $page->text('.//a', $cells[1]),
                'code' => $code,
                'title' => $title === '' ? null : $title,
                'approved_on' => self::date($page->text('.', $cells[4])),
                'sites' => self::sites($cells[3]),
            ];
        }

        return ['rows' => $rows, 'page' => (int) $counter[0], 'pages' => max(1, (int) $counter[1])];
    }

    /**
     * Sites listed after the "Site(s) in Kenya" heading of a list cell, one per line, each
     * optionally followed by its county; the investigators listed before it are skipped.
     *
     * @return list<array{name: string, county: ?string}>
     */
    private static function sites(DOMNode $cell): array
    {
        $sites = [];
        $inSites = false;
        $name = '';
        $county = null;
        $flush = static function () use (&$sites, &$name, &$county): void {
            $clean = trim((string) preg_replace('/^\d+\.\s*/', '', self::clean($name)));

            if ($clean !== '') {
                $sites[] = ['name' => str_replace("\\'", "'", $clean), 'county' => $county];
            }

            $name = '';
            $county = null;
        };

        foreach ($cell->childNodes as $node) {
            if (! $inSites) {
                $inSites = $node->nodeName === 'small' && str_contains($node->textContent, 'Site(s)');

                continue;
            }

            match ($node->nodeName) {
                'br' => $flush(),
                'small' => $county = self::county($node->textContent),
                default => $name .= $node->textContent,
            };
        }

        $flush();

        return $sites;
    }

    /** "(Nairobi City county)" → "Nairobi City". */
    private static function county(string $text): ?string
    {
        $county = trim((string) preg_replace('/\s*county\s*$/i', '', trim(self::clean($text), '() ')));

        return $county === '' ? null : $county;
    }

    private static function clean(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /** A dd-mm-yyyy date as YYYY-MM-DD. */
    public static function date(?string $value): ?string
    {
        if ($value === null || preg_match('/(\d{2})-(\d{2})-(\d{4})/', $value, $match) !== 1 || ! checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
            return null;
        }

        return "{$match[3]}-{$match[2]}-{$match[1]}";
    }

    /**
     * Whether the dated rows of a page are newest first, the order the early stop relies on.
     *
     * @param  list<array{approved_on: ?string}>  $rows
     */
    private static function inDateOrder(array $rows): bool
    {
        $previous = null;

        foreach ($rows as $row) {
            if ($row['approved_on'] === null) {
                continue;
            }

            if ($previous !== null && $row['approved_on'] > $previous) {
                return false;
            }

            $previous = $row['approved_on'];
        }

        return true;
    }

    /**
     * @param  array{view_id: int, protocol_no: ?string, code: ?string, title: ?string, approved_on: ?string, sites: list<array{name: string, county: ?string}>}  $row
     * @return array<string, mixed>
     */
    private function record(array $row, string $status): array
    {
        $url = PpbKenyaSource::viewUrl($row['view_id']);
        $document = $this->fetcher->get($this->source, $url);

        if ((new HtmlDocument($document->body))->nodes("//div[@id='applicationPrintArea']") === []) {
            throw new RegistryRequestFailed("The PPB Kenya page {$url} does not contain a trial record.");
        }

        return [...$row, 'list_status' => $status, 'url' => $url, 'html' => $document->body];
    }
}
