<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\MozUkraine;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\Parsing\HtmlDocument;
use App\Services\Registries\Parsing\PdfTextExtractor;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;

/**
 * Reads MOZ orders authorising clinical trials: orders listing (newest first, ten per page)
 * → order page → annex PDF (`dn_NNNN_DDMMYYYY_dod.pdf`) → one payload per annex that
 * authorises a new trial. Annexes approving substantial amendments are skipped: they repeat
 * only the trial's title and sponsor, and importing them would empty the lists of the
 * authorised trial.
 *
 * One listing page is read per call; the page token is the next listing page number. The
 * listing goes back to 2014, so a run stops after `registries.options.moz_ukraine.max_pages`
 * listing pages, or, with `updatedSince`, at the first page holding only older orders.
 *
 * The annex text is extracted with pdftotext -layout: its two-column label/value table keeps
 * labels from column 0 and values from a fixed column (which varies per page and annex).
 */
final class MozUkraineClient implements StudyRegistryClient
{
    public const string LISTING_URL = 'https://moz.gov.ua/uk/nakazi-moz';

    public const int DEFAULT_MAX_PAGES = 5;

    /** Field keys by the start of their label in the annex table. */
    public const array LABELS = [
        'Назва клінічного випробування' => 'title',
        'Заявник' => 'applicant',
        'Спонсор' => 'sponsor',
        'Перелік досліджуваних' => 'imps',
        'Відповідальний' => 'sites',
        'Препарати порівняння' => 'comparators',
        'Допоміжні лікарські' => 'auxiliary',
        'Ідентифікація суттєвої поправки' => 'amendment',
        'Номер та дата наказу' => 'authorisation_order',
    ];

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly PdfTextExtractor $extractor,
        private readonly RegistrySource $source,
    ) {}

    #[\Override]
    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        $page = max(1, (int) ($pageToken ?? 1));
        $listing = $this->fetcher->get($this->source, self::LISTING_URL, ['searchTypeId' => 0, 'page' => $page]);
        $orders = self::orders($listing->body, self::LISTING_URL);
        $since = $updatedSince?->toDateString();
        $studies = [];
        $older = 0;

        foreach ($orders as $order) {
            if ($since !== null && $order['date'] !== null && $order['date'] < $since) {
                $older++;

                continue;
            }

            if (! $order['clinical_trials']) {
                continue;
            }

            array_push($studies, ...$this->readOrder($order));
        }

        $maxPages = max(1, (int) config('registries.options.moz_ukraine.max_pages', self::DEFAULT_MAX_PAGES));
        $done = $orders === [] || ($since !== null && $older === count($orders)) || ($since === null && $page >= $maxPages);

        return new StudyPage($studies, $done ? null : (string) ($page + 1));
    }

    /**
     * Orders of a listing page, newest first.
     *
     * @return list<array{url: string, number: string|null, date: string|null, title: string, clinical_trials: bool}>
     *
     * @throws RegistryRequestFailed when the page has no results block
     */
    public static function orders(string $html, string $pageUrl): array
    {
        $document = new HtmlDocument($html);

        if ($document->nodes("//*[contains(concat(' ', normalize-space(@class), ' '), ' grid-info ')]") === []) {
            throw new RegistryRequestFailed('The MOZ orders listing has no results block; its layout may have changed.');
        }

        $orders = [];

        foreach ($document->nodes("//*[contains(concat(' ', normalize-space(@class), ' '), ' grid-info ')]//div[contains(concat(' ', normalize-space(@class), ' '), ' item ')]//a[contains(@href, '/decrees/')]") as $link) {
            if (! $link instanceof \DOMElement) {
                continue;
            }

            $url = self::absoluteUrl(trim($link->getAttribute('href')), $pageUrl);
            $title = trim((string) preg_replace('/\s+/u', ' ', $link->textContent));
            $slug = strtolower((string) parse_url($url, PHP_URL_PATH));
            preg_match('/від\s+(\d{2})\.(\d{2})\.(\d{4})\s*№\s*([\w\/-]+)/u', $title, $match);

            $orders[] = [
                'url' => $url,
                'number' => $match[4] ?? null,
                'date' => isset($match[3]) ? "{$match[3]}-{$match[2]}-{$match[1]}" : null,
                'title' => $title,
                'clinical_trials' => str_contains($slug, 'klinichn') && str_contains($slug, 'viprobuvan'),
            ];
        }

        return $orders;
    }

    /**
     * Annex PDFs linked from an order page (`…_dod.pdf`, or `…_dod_N.pdf`).
     *
     * @return list<string>
     */
    public static function annexUrls(string $html, string $pageUrl): array
    {
        $urls = [];

        foreach ((new HtmlDocument($html))->nodes("//a[contains(@href, '.pdf')]") as $link) {
            $href = $link instanceof \DOMElement ? trim($link->getAttribute('href')) : '';

            if (preg_match('/_dod(?:_?\d+)?\.pdf$/i', (string) parse_url($href, PHP_URL_PATH)) === 1) {
                $urls[self::absoluteUrl($href, $pageUrl)] = true;
            }
        }

        return array_keys($urls);
    }

    /**
     * The annexes of an order's annex PDF text, each with its number, the order's date and
     * number from the annex header, and its fields as lists of value lines keyed by LABELS.
     *
     * @return list<array{annex: string, order_date: string|null, order_number: string|null, fields: array<string, list<string>>}>
     */
    public static function annexes(string $text): array
    {
        $annexes = [];
        $current = null;
        $inHeader = false;

        $flush = static function () use (&$annexes, &$current): void {
            if ($current !== null) {
                unset($current['segment'], $current['field']);
                $annexes[] = $current;
            }
        };

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = rtrim($line);
            $newPage = str_contains($line, "\f");
            $line = str_replace("\f", '', $line);

            if (preg_match('/^\s{20,}Додаток\s+(\d+)\s*$/u', $line, $match) === 1) {
                $flush();
                $current = ['annex' => $match[1], 'order_date' => null, 'order_number' => null, 'fields' => [], 'segment' => [], 'field' => null];
                $inHeader = true;

                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($inHeader) {
                if (preg_match('/^\s+(\d{2})\.(\d{2})\.(\d{4})\s*№\s*([\w\/-]+)\s*$/u', $line, $match) === 1) {
                    $current['order_date'] = "{$match[3]}-{$match[2]}-{$match[1]}";
                    $current['order_number'] = $match[4];
                }

                if ($line === '' || preg_match('/^\S/u', $line) !== 1) {
                    continue;
                }

                $inHeader = false;
            }

            if ($newPage || preg_match('/продовження\s+додатка/u', $line) === 1) {
                // Columns may shift on a new page.
                $current['segment'] = [];

                if (preg_match('/продовження\s+додатка/u', $line) === 1) {
                    continue;
                }
            }

            if (preg_match('/^Начальник\s|\(підпис\)/u', $line) === 1) {
                $current['field'] = null;

                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            [$label, $value] = self::split($line, $current['segment']);

            if ($label !== null) {
                $field = self::fieldFor($label);

                if ($field !== null) {
                    $current['field'] = $field;
                    $current['fields'][$field] ??= [];
                }
            }

            if ($value !== null && $current['field'] !== null) {
                $current['fields'][$current['field']][] = $value;
            }
        }

        $flush();

        return $annexes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readOrder(array $order): array
    {
        $page = $this->fetcher->get($this->source, $order['url']);
        $studies = [];

        foreach (self::annexUrls($page->body, $order['url']) as $annexUrl) {
            $document = $this->fetcher->get($this->source, $annexUrl);

            if (! str_starts_with(ltrim(substr($document->body, 0, 1024)), '%PDF-')) {
                throw new RegistryRequestFailed("The MOZ annex at {$annexUrl} is not a PDF.");
            }

            $path = $document->toTemporaryFile('pdf');

            try {
                $text = $this->extractor->extract($path);
            } catch (\RuntimeException $exception) {
                throw new RegistryRequestFailed('The MOZ annex PDF could not be read: '.$exception->getMessage(), 0, $exception);
            } finally {
                @unlink($path);
            }

            $annexes = self::annexes($text);

            if ($annexes === [] && trim($text) !== '') {
                throw new RegistryRequestFailed("No annex found in {$annexUrl}; the text extraction or the layout may have changed.");
            }

            foreach ($annexes as $annex) {
                if (isset($annex['fields']['amendment']) || ! isset($annex['fields']['title'])) {
                    continue;
                }

                $studies[] = [
                    ...$annex,
                    'order_date' => $annex['order_date'] ?? $order['date'],
                    'order_number' => $annex['order_number'] ?? $order['number'],
                    'order_url' => $order['url'],
                    'url' => $document->url,
                ];
            }
        }

        return $studies;
    }

    /**
     * A table line split into label (column 0) and value. The value column of the current page
     * is learnt from the first line whose label and value are separated by two spaces or more.
     *
     * @param  array{column?: int}  $segment
     * @return array{0: string|null, 1: string|null}
     */
    private static function split(string $line, array &$segment): array
    {
        if (preg_match('/^\s/u', $line) === 1) {
            return [null, trim($line)];
        }

        // Values start a character or two apart from line to line: a line with a clear gap is
        // split at the gap; a label running up to its value (one space) at the learnt column.
        if (preg_match('/^(\S.*?\S)\s{2,}\S/u', $line, $match) === 1) {
            $column = mb_strlen($match[0]) - 1;
            $segment['column'] ??= $column;
        } else {
            $column = isset($segment['column']) ? self::spaceNear($line, $segment['column']) : null;
        }

        if ($column === null) {
            return [trim($line), null];
        }

        $label = trim(mb_substr($line, 0, $column));
        $value = trim(mb_substr($line, $column));

        return [$label === '' ? null : $label, $value === '' ? null : $value];
    }

    /** Start of the word after the space nearest to the column (within 3 characters), if any. */
    private static function spaceNear(string $line, int $column): ?int
    {
        $characters = mb_str_split($line);
        $best = null;

        for ($position = max(1, $column - 3); $position <= $column + 3 && $position < count($characters); $position++) {
            if ($characters[$position - 1] === ' ' && preg_match('/[\p{L}\p{N}«(―]/u', $characters[$position]) === 1
                && ($best === null || abs($position - $column) < abs($best - $column))) {
                $best = $position;
            }
        }

        return $best;
    }

    private static function fieldFor(string $label): ?string
    {
        foreach (self::LABELS as $start => $field) {
            if (str_starts_with($label, $start)) {
                return $field;
            }
        }

        return null;
    }

    private static function absoluteUrl(string $href, string $pageUrl): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $parts = parse_url($pageUrl);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').'/'.ltrim($href, '/');
    }
}
