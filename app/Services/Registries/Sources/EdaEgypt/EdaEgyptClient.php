<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\EdaEgypt;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\Parsing\HtmlDocument;
use App\Services\Registries\Parsing\PdfTextExtractor;
use App\Services\Registries\Parsing\TextTable;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;

/**
 * Reads the EDA clinical trials registry: the landing page lists every edition of a cumulative
 * PDF (linked under a random media folder, so the newest link is read from the page, unless
 * `registries.options.eda_egypt.pdf_url` names one); the PDF's table is turned into one
 * payload per study. Each edition repeats every study, so a run reads the whole newest PDF
 * once and pages through its rows with an offset token; `updatedSince` is ignored.
 *
 * Table layout (pdftotext -layout): ten columns, each logical row spanning many lines and
 * often continuing on the next page, with a colour legend and a form footer on every page.
 * The header is printed once; its labels are centred while cells are left-aligned, so the
 * column boundaries of each page are taken from the blank gutters of that page, nearest to
 * the header's labels, and a header line is written at the start of each page for TextTable.
 */
final class EdaEgyptClient implements StudyRegistryClient
{
    public const string DEFAULT_LANDING_URL = 'https://www.edaegypt.gov.eg/en/publications-reports-and-eda-in-numbers/eda-publications/periodic-lists/periodic-list-of-ca-of-of-biological-and-innovative-products-and-clinical-studies/';

    /** Labels on the first line of the table header, in order, used to find the table. */
    public const array HEADER = ['SN', 'Submission', 'Study', 'Sponsor/', 'Study Title', 'Study', 'Sites', 'Status/Date', 'Conditions', 'Interventions'];

    /**
     * Distinct column labels written at each page's column boundaries for TextTable, taken from
     * the header's lines ("Study" heads two columns, over "Code" and "Phase").
     */
    public const array COLUMNS = ['SN', 'Submission', 'Code', 'Sponsor', 'Study Title', 'Phase', 'Sites', 'Status', 'Conditions', 'Interventions'];

    /** Payload keys of the columns, in the order of COLUMNS. */
    public const array FIELDS = ['sn', 'submission_date', 'study_code', 'sponsor', 'title', 'phase', 'sites', 'status', 'conditions', 'interventions'];

    /** Lines repeated on every page: title, colour legend, form footer. */
    private const array NOISE = [
        '/^\s*Clinical Trials Registry at EDA\s*$/i',
        '/^\s*Registry updated\s+\S+\s*$/i',
        '/QF:\s*BioInn|Page\s+\d+\s+of\s+\d+/i',
        '/^\s*(?:Color|Indicator)?\s*(?:Green|Blue|Orange|Gray|Grey|Red)?\s*(?:Biological|Pharmaceutical|Medical\s+Device|Innovative|Herbal)\s*$/i',
        '/^\s*(?:Color|Indicator)\s*$/i',
    ];

    /** How far before and after its label a column may start (characters). */
    private const int EDGE_BEFORE = 12;

    private const int EDGE_AFTER = 3;

    /** @var array{url: string, studies: list<array<string, string|null>>}|null */
    private ?array $edition = null;

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly PdfTextExtractor $extractor,
        private readonly RegistrySource $source,
    ) {}

    #[\Override]
    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        if ($pageToken === null || $this->edition === null) {
            $this->edition = $this->readEdition();
        }

        $offset = max(0, (int) $pageToken);
        $studies = array_slice($this->edition['studies'], $offset, max(1, $pageSize));
        $next = $offset + count($studies);

        return new StudyPage($studies, $next < count($this->edition['studies']) ? (string) $next : null);
    }

    /**
     * The newest edition linked from the landing page: links whose text is the edition date
     * (dd-mm-yyyy or dd/mm/yyyy), in the page's content block.
     */
    public static function latestPdfUrl(string $html, string $pageUrl): ?string
    {
        $document = new HtmlDocument($html);
        $latest = null;

        foreach ($document->nodes("//div[contains(@class, 'news-details-description')]//a[contains(translate(@href, 'PDF', 'pdf'), '.pdf')]") as $link) {
            if (! $link instanceof \DOMElement || preg_match('#(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})#', (string) $link->textContent, $match) !== 1) {
                continue;
            }

            if (! checkdate((int) $match[2], (int) $match[1], (int) $match[3])) {
                continue;
            }

            $date = sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]);

            if ($latest === null || $date > $latest[0]) {
                $latest = [$date, self::absoluteUrl(trim($link->getAttribute('href')), $pageUrl)];
            }
        }

        return $latest[1] ?? null;
    }

    /**
     * Rows of the registry table, keyed by FIELDS, with the edition date of the PDF.
     *
     * @return array{edition: string|null, rows: list<array<string, string|null>>}
     *
     * @throws RegistryRequestFailed when the text has no registry table header
     */
    public static function parseRegistry(string $text): array
    {
        $edition = preg_match('#Registry updated\s+(\d{1,2}/\d{1,2}/\d{4})#i', $text, $match) === 1 ? $match[1] : null;
        $labels = null;
        $lines = [];

        foreach (explode("\f", $text) as $page) {
            $body = [];

            foreach (preg_split('/\R/u', $page) ?: [] as $line) {
                if ($labels === null) {
                    $labels = self::labelOffsets($line);

                    continue;
                }

                if (trim($line) === '' || self::isNoise($line)) {
                    continue;
                }

                // Skip the rest of the multi-line header, up to the first row (text in the SN column).
                if ($lines === [] && $body === [] && mb_strlen(rtrim(mb_substr($line, 0, $labels[1]))) === 0) {
                    continue;
                }

                $body[] = rtrim($line);
            }

            if ($labels === null || $body === []) {
                continue;
            }

            $lines[] = self::headerLine(self::cuts($body, $labels));
            array_push($lines, ...$body);
        }

        if ($labels === null) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $rows = [];

        foreach ((new TextTable(self::COLUMNS))->parse(implode("\n", $lines)) as $cells) {
            $rows[] = array_combine(self::FIELDS, array_values($cells));
        }

        return ['edition' => $edition, 'rows' => $rows];
    }

    /**
     * @return array{url: string, studies: list<array<string, string|null>>}
     */
    private function readEdition(): array
    {
        $pdfUrl = config('registries.options.eda_egypt.pdf_url');

        if (! is_string($pdfUrl) || $pdfUrl === '') {
            $landingUrl = (string) config('registries.options.eda_egypt.landing_url', self::DEFAULT_LANDING_URL);
            $landing = $this->fetcher->get($this->source, $landingUrl);
            $pdfUrl = self::latestPdfUrl($landing->body, $landingUrl);

            if ($pdfUrl === null) {
                throw new RegistryRequestFailed('No registry PDF is linked from the EDA landing page; its layout may have changed.');
            }
        }

        $document = $this->fetcher->get($this->source, $pdfUrl);

        if (! str_starts_with(ltrim(substr($document->body, 0, 1024)), '%PDF-')) {
            throw new RegistryRequestFailed("The EDA registry file at {$pdfUrl} is not a PDF.");
        }

        $path = $document->toTemporaryFile('pdf');

        try {
            $text = $this->extractor->extract($path);
        } catch (\RuntimeException $exception) {
            throw new RegistryRequestFailed('The EDA registry PDF could not be read: '.$exception->getMessage(), 0, $exception);
        } finally {
            @unlink($path);
        }

        $parsed = self::parseRegistry($text);
        $studies = [];

        foreach ($parsed['rows'] as $row) {
            $studies[] = [...$row, 'edition' => $parsed['edition'], 'url' => $document->url];
        }

        return ['url' => $document->url, 'studies' => $studies];
    }

    /**
     * Character offset of each header label when the line is the table header.
     *
     * @return list<int>|null
     */
    private static function labelOffsets(string $line): ?array
    {
        $offsets = [];
        $from = 0;

        foreach (self::HEADER as $label) {
            $position = mb_strpos($line, $label, $from);

            if ($position === false) {
                return null;
            }

            $offsets[] = $position;
            $from = $position + mb_strlen($label);
        }

        return $offsets;
    }

    private static function isNoise(string $line): bool
    {
        foreach (self::NOISE as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Column boundaries of a page. Labels are centred over their columns and cells are
     * left-aligned, so a column starts at the left edge of a text block (a position empty in
     * every line, followed by text) a little before its label: the edge nearest to the label
     * within that window. A column without text on the page is cut where no word is split.
     *
     * @param  list<string>  $lines
     * @param  list<int>  $labels
     * @return list<int>
     */
    private static function cuts(array $lines, array $labels): array
    {
        $width = max(array_map('mb_strlen', $lines)) + 1;
        $occupied = array_fill(0, $width + 1, false);

        foreach ($lines as $line) {
            foreach (mb_str_split($line) as $position => $character) {
                if (trim($character) !== '') {
                    $occupied[$position] = true;
                }
            }
        }

        // The serial number column ends after the widest serial number ("12." or "- 72.").
        $serial = 1;

        foreach ($lines as $line) {
            if (preg_match('/^\s{0,4}-?\s*\d{1,3}\s*\./u', $line, $match) === 1) {
                $serial = max($serial, mb_strlen($match[0]) + 1);
            }
        }

        $cuts = [0];

        foreach (array_slice($labels, 1) as $index => $label) {
            $previous = $index === 0 ? $serial : $cuts[$index] + 1;
            $edge = null;
            $safe = null;

            for ($position = $previous; $position <= $width; $position++) {
                if ($occupied[$position - 1]) {
                    continue;
                }

                $distance = abs($position - $label);

                if ($occupied[$position] && $position >= $label - self::EDGE_BEFORE && $position <= $label + self::EDGE_AFTER
                    && ($edge === null || $distance < abs($edge - $label))) {
                    $edge = $position;
                }

                if ($safe === null || $distance < abs($safe - $label)) {
                    $safe = $position;
                }
            }

            $cuts[] = $edge ?? $safe ?? max($previous, $label);
        }

        return $cuts;
    }

    /**
     * A header line with each label at its cut, or the labels at fixed positions when two
     * cuts are too close for their labels (TextTable then keeps the previous columns).
     *
     * @param  list<int>  $cuts
     */
    private static function headerLine(array $cuts): string
    {
        $line = '';

        foreach (self::COLUMNS as $index => $label) {
            if (mb_strlen($line) > $cuts[$index]) {
                return '';
            }

            $line = str_pad($line, $cuts[$index]).$label;
        }

        return $line;
    }

    private static function absoluteUrl(string $href, string $pageUrl): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $parts = parse_url($pageUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');

        return str_starts_with($href, '/') ? $origin.$href : rtrim($origin.dirname($parts['path'] ?? '/'), '/').'/'.$href;
    }
}
