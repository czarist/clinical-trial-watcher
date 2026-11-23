<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Renis;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\Parsing\HtmlDocument;
use App\Services\Registries\Parsing\XlsxReader;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;

/**
 * Reads RENIS from its cumulative XLSX on datos.salud.gob.ar. The file name changes with each
 * release and the portal's CKAN API is disallowed by robots.txt, so the newest workbook is
 * found on the dataset page (or set by the operator in registries.options.renis.file_url).
 * The workbook is downloaded once per run through the RegistryFetcher and its rows are
 * streamed; page tokens are row offsets.
 *
 * The file has no per-record update date, so every run reads the whole file and updatedSince
 * is ignored. The person-name and email columns are dropped before the rows leave the client.
 */
final class RenisClient implements StudyRegistryClient
{
    public const string DATASET_URL = 'https://datos.salud.gob.ar/dataset/registro-nacional-de-investigaciones-en-salud-renis';

    /** Columns holding personal data (the registering person and their email). */
    public const array PERSONAL_COLUMNS = ['RESPONSABLE_REGISTRO', 'MAIL'];

    private const array REQUIRED_COLUMNS = ['ID_RENIS', 'TITULO_COMPLETO_ENSAYO', 'ESTADO_RECLUTAMIENTO'];

    private const array MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
        'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    private ?string $path = null;

    private ?string $fileUrl = null;

    /** @var \Generator<int, array<string, string|null>>|null rows being read, kept between pages */
    private ?\Generator $rows = null;

    private int $position = 0;

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly XlsxReader $reader = new XlsxReader,
    ) {}

    public function __destruct()
    {
        $this->rows = null;

        if ($this->path !== null && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    /**
     * URL of the newest XLSX listed on the dataset page, dated by the release title
     * ("... - Agosto 2026") or, failing that, the file name; the last listed wins a tie.
     */
    public static function newestWorkbookUrl(string $html, string $pageUrl = self::DATASET_URL): ?string
    {
        $page = new HtmlDocument($html);
        $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $pageUrl);
        $best = null;
        $bestScore = -1;

        foreach ($page->nodes('//a[contains(translate(@href, "XLSX", "xlsx"), ".xlsx")]') as $link) {
            $href = $link instanceof \DOMElement ? trim($link->getAttribute('href')) : '';
            $url = str_starts_with($href, '/') ? $origin.$href : $href;

            if (preg_match('#^https?://([^/]+\.)?salud\.gob\.ar/#i', $url) !== 1) {
                continue;
            }

            $title = $page->text('ancestor::div[contains(concat(" ", normalize-space(@class), " "), " pkg-container ")][1]//h3', $link);
            $score = self::releaseScore((string) $title) ?? self::releaseScore(basename((string) parse_url($url, PHP_URL_PATH))) ?? 0;

            if ($score >= $bestScore) {
                $best = $url;
                $bestScore = $score;
            }
        }

        return $best;
    }

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        $this->ensureWorkbook();

        if ($pageToken !== null && $pageToken !== '' && ! ctype_digit($pageToken)) {
            throw new RegistryRequestFailed("Invalid RENIS page token {$pageToken}.");
        }

        $rows = $this->rowsFrom((int) $pageToken);
        $studies = [];

        while ($rows->valid() && count($studies) < $pageSize) {
            $studies[] = $this->payload($rows->current());
            $rows->next();
            $this->position++;
        }

        return new StudyPage($studies, $rows->valid() ? (string) $this->position : null);
    }

    /** Year and month of a release named like "Agosto 2026" or "hasta-agosto-2026", as YYYYMM. */
    private static function releaseScore(string $text): ?int
    {
        $text = mb_strtolower($text);

        if (preg_match('/('.implode('|', array_keys(self::MONTHS)).')[\s_\-.]*(?:de[\s_\-]*)?((?:19|20)\d{2})/u', $text, $match) === 1) {
            return (int) $match[2] * 100 + self::MONTHS[$match[1]];
        }

        return preg_match('/((?:19|20)\d{2})/', $text, $match) === 1 ? (int) $match[1] * 100 : null;
    }

    private function ensureWorkbook(): void
    {
        if ($this->path !== null) {
            return;
        }

        $url = config('registries.options.renis.file_url');

        if (! is_string($url) || $url === '') {
            $page = $this->fetcher->get($this->source, self::DATASET_URL);
            $url = self::newestWorkbookUrl($page->body)
                ?? throw new RegistryRequestFailed('No XLSX file is listed on the RENIS dataset page.');
        }

        $document = $this->fetcher->get($this->source, $url);

        if (! str_starts_with($document->body, "PK\x03\x04")) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $path = $document->toTemporaryFile('xlsx');
        unset($document);

        try {
            $header = $this->reader->header($path);
        } catch (RegistryRequestFailed $exception) {
            @unlink($path);
            throw $exception;
        }

        if (array_diff(self::REQUIRED_COLUMNS, $header) !== []) {
            @unlink($path);
            throw RegistryRequestFailed::invalidPayload();
        }

        $this->path = $path;
        $this->fileUrl = $url;
    }

    /**
     * @return \Generator<int, array<string, string|null>>
     */
    private function rowsFrom(int $offset): \Generator
    {
        if ($this->rows === null || $this->position !== $offset) {
            $this->rows = $this->reader->rows((string) $this->path);
            $this->position = 0;

            while ($this->position < $offset && $this->rows->valid()) {
                $this->rows->next();
                $this->position++;
            }
        }

        return $this->rows;
    }

    /**
     * @param  array<string, string|null>  $row
     * @return array<string, string|null>
     */
    private function payload(array $row): array
    {
        foreach (self::PERSONAL_COLUMNS as $column) {
            unset($row[$column]);
        }

        return $row + [RenisMapper::FILE_URL_KEY => $this->fileUrl];
    }
}
