<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Umin;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\Parsing\CsvReader;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;

/**
 * Reads UMIN-CTR from its daily dump, a gzip of one UTF-8 CSV file with LF line ends (UMIN's
 * download page). The dump is downloaded once per run through the RegistryFetcher (its stored
 * copy answers repeated runs while it is unchanged), written to a temporary file and read row by
 * row through the compress.zlib:// stream, so the decompressed CSV (hundreds of MB) is never held
 * in memory. Page tokens are the next row number and the dump's size; a run reading pages in
 * order keeps one open reader instead of skipping rows again for every page.
 *
 * UMIN publishes no updated-since query: incremental runs keep rows whose last-modified column
 * (unverified name, see UminMapper::LAST_MODIFIED) is on or after the date, and rows without one.
 */
final class UminClient implements StudyRegistryClient
{
    /** The English-only dump (UMIN's download page). */
    public const string DUMP_URL = 'https://upload.umin.ac.jp/ctr_csv/ctr_data_e.csv.gz';

    /** Longest header line read when checking the dump's layout. */
    private const int MAX_HEADER_BYTES = 1_048_576;

    private ?string $path = null;

    private int $size = 0;

    /** @var \Generator<int, array<string, string|null>>|null */
    private ?\Generator $rows = null;

    private int $position = 0;

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly string $dumpUrl = self::DUMP_URL,
    ) {}

    public function __destruct()
    {
        $this->rows = null;

        if ($this->path !== null && is_file($this->path)) {
            @unlink($this->path);
        }
    }

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        $this->ensureDump();
        $rows = $this->rowsFrom($this->offsetFrom($pageToken));
        $since = $updatedSince?->toDateString();
        $studies = [];

        while ($rows->valid()) {
            $row = $rows->current();
            $rows->next();
            $this->position++;

            if ($since !== null && ! self::modifiedSince($row, $since)) {
                continue;
            }

            $studies[] = $row;

            if (count($studies) >= max(1, $pageSize)) {
                break;
            }
        }

        return new StudyPage($studies, $rows->valid() ? $this->position.':'.$this->size : null);
    }

    /**
     * Whether the row was modified on or after the date; rows without a readable date are kept.
     *
     * @param  array<string, string|null>  $row
     */
    public static function modifiedSince(array $row, string $since): bool
    {
        $value = UminMapper::value($row, UminMapper::LAST_MODIFIED);

        if ($value === null || preg_match('/^(\d{4})[\/-](\d{1,2})[\/-](\d{1,2})/', trim($value), $match) !== 1) {
            return true;
        }

        return sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3]) >= $since;
    }

    /**
     * The header of a gzip CSV dump, read with a bounded line length.
     *
     * @return list<string>
     *
     * @throws RegistryRequestFailed when the file is not a gzip CSV with the expected columns
     */
    public static function readHeader(string $path): array
    {
        $handle = fopen($path, 'rb');
        $magic = $handle !== false ? fread($handle, 2) : false;

        if ($handle !== false) {
            fclose($handle);
        }

        // compress.zlib:// would read a plain file as is: check the gzip signature first.
        if ($magic !== "\x1f\x8b") {
            throw new RegistryRequestFailed('The UMIN-CTR dump is not a gzip file; the download address or format may have changed.');
        }

        $stream = fopen('compress.zlib://'.$path, 'rb');

        if ($stream === false) {
            throw new RegistryRequestFailed('The UMIN-CTR dump cannot be decompressed.');
        }

        try {
            $line = fgets($stream, self::MAX_HEADER_BYTES);
        } finally {
            fclose($stream);
        }

        $header = is_string($line) ? str_getcsv(preg_replace('/^\x{FEFF}/u', '', rtrim($line, "\r\n")) ?? '', ',', '"', '') : [];
        $header = array_map(static fn (?string $name): string => trim((string) $name), $header);

        if (! UminMapper::hasIdColumn($header)) {
            throw new RegistryRequestFailed('The UMIN-CTR dump has no UMIN trial number column ('.UminMapper::ID[0].'); its layout may have changed.');
        }

        return $header;
    }

    /**
     * @return \Generator<int, array<string, string|null>>
     */
    private function rowsFrom(int $offset): \Generator
    {
        if ($this->rows === null || $offset !== $this->position) {
            $this->rows = (new CsvReader)->rows('compress.zlib://'.$this->path);
            $this->position = 0;

            while ($this->position < $offset && $this->rows->valid()) {
                $this->rows->next();
                $this->position++;
            }
        }

        return $this->rows;
    }

    private function offsetFrom(?string $pageToken): int
    {
        if ($pageToken === null || $pageToken === '') {
            return 0;
        }

        if (preg_match('/^(\d+):(\d+)$/', $pageToken, $match) !== 1) {
            throw new RegistryRequestFailed("Invalid UMIN-CTR page token {$pageToken}.");
        }

        if ((int) $match[2] !== $this->size) {
            throw new RegistryRequestFailed('The UMIN-CTR dump changed during the import; run the import again.');
        }

        return (int) $match[1];
    }

    /** Downloads the dump once and checks that it is the expected gzip CSV. */
    private function ensureDump(): void
    {
        if ($this->path !== null) {
            return;
        }

        $document = $this->fetcher->get($this->source, $this->dumpUrl);
        $path = $document->toTemporaryFile('gz');
        unset($document);

        try {
            self::readHeader($path);
        } catch (RegistryRequestFailed $exception) {
            @unlink($path);

            throw $exception;
        }

        $this->path = $path;
        $this->size = (int) filesize($path);
    }
}
