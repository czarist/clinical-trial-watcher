<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Tfda;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\Parsing\CsvReader;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;
use ZipArchive;

/**
 * Reads the TFDA drug clinical trial export (open data 205): a ZIP holding one UTF-8 CSV with
 * several rows per trial. The ZIP is downloaded once per run through the RegistryFetcher
 * (which stores it, so an unchanged export is not downloaded again), checked for truncation,
 * and its rows are grouped by TFDA document number. Each page lists the next trials of that
 * grouping; the page token is the offset of the next trial.
 *
 * Memory stays bounded: the rows are written to a temporary JSON-lines file while the CSV is
 * read, and only the byte offsets of each trial's rows are kept in memory.
 *
 * The export has no incremental filter, so `updatedSince` is ignored; unchanged trials are
 * skipped by the importer's content hash.
 */
final class TfdaClient implements StudyRegistryClient
{
    public const string DEFAULT_URL = 'https://data.fda.gov.tw/data/opendata/export/205/csv';

    public const string ID_COLUMN = 'TFDA收文號';

    /** Columns whose absence means the export changed its layout. */
    public const array REQUIRED_COLUMNS = [self::ID_COLUMN, '臨床試驗計畫中文名稱', '臨床試驗計畫書編號', '資料更新時間'];

    /** @var array{url: string, header: list<string>, rows: string, offsets: array<string, list<int>>}|null */
    private ?array $prepared = null;

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
    ) {}

    public function __destruct()
    {
        $this->forget();
    }

    #[\Override]
    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        if ($pageToken === null || $this->prepared === null) {
            $this->prepare();
        }

        assert($this->prepared !== null);
        $ids = array_keys($this->prepared['offsets']);
        $offset = max(0, (int) $pageToken);
        $slice = array_slice($ids, $offset, max(1, $pageSize));
        $studies = [];

        $handle = fopen($this->prepared['rows'], 'rb');

        if ($handle === false) {
            throw new RegistryRequestFailed('Could not read the prepared TFDA rows.');
        }

        try {
            foreach ($slice as $id) {
                $rows = [];

                foreach ($this->prepared['offsets'][$id] as $position) {
                    fseek($handle, $position);
                    $rows[] = json_decode((string) fgets($handle), true, 512, JSON_THROW_ON_ERROR);
                }

                $studies[] = [
                    'document_number' => str_starts_with((string) $id, 'row:') ? null : (string) $id,
                    'header' => $this->prepared['header'],
                    'rows' => $rows,
                    'url' => $this->prepared['url'],
                ];
            }
        } finally {
            fclose($handle);
        }

        $next = $offset + count($slice);

        return new StudyPage($studies, $next < count($ids) ? (string) $next : null);
    }

    /**
     * Extracts the single CSV of the export to $csvPath, refusing an incomplete archive: the
     * export is sent without Content-Length and downloads have been seen to stop early.
     *
     * @throws RegistryRequestFailed when the archive is truncated, corrupt or holds no CSV
     */
    public static function extractCsv(string $zipPath, string $csvPath): void
    {
        $zip = new ZipArchive;
        $opened = $zip->open($zipPath, ZipArchive::CHECKCONS | ZipArchive::RDONLY);

        if ($opened !== true) {
            throw new RegistryRequestFailed("The TFDA export is not a complete ZIP archive (ZipArchive error {$opened}); the download was probably cut short.");
        }

        try {
            $entry = null;

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                if ($stat !== false && str_ends_with(strtolower($stat['name']), '.csv')) {
                    $entry = $stat;

                    break;
                }
            }

            if ($entry === null) {
                throw new RegistryRequestFailed('The TFDA export holds no CSV file.');
            }

            $input = $zip->getStream($entry['name']);
            $output = fopen($csvPath, 'wb');

            if ($input === false || $output === false) {
                throw new RegistryRequestFailed('Could not extract the CSV of the TFDA export.');
            }

            $crc = hash_init('crc32b');
            $size = 0;

            while (! feof($input)) {
                $chunk = fread($input, 1 << 16);

                if ($chunk === false) {
                    break;
                }

                hash_update($crc, $chunk);
                // The byte order mark is dropped here: before a quoted first header field,
                // CsvReader would keep the field's quotes.
                fwrite($output, $size === 0 && str_starts_with($chunk, "\xEF\xBB\xBF") ? substr($chunk, 3) : $chunk);
                $size += strlen($chunk);
            }

            fclose($input);
            fclose($output);

            if ($size !== (int) $entry['size'] || hexdec(hash_final($crc)) !== ($entry['crc'] & 0xFFFFFFFF)) {
                throw new RegistryRequestFailed('The CSV in the TFDA export fails its size or CRC check; the download was probably cut short.');
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Header labels of the CSV, without the byte order mark.
     *
     * @return list<string>
     *
     * @throws RegistryRequestFailed when a required column is missing
     */
    public static function header(string $csvPath): array
    {
        $handle = fopen($csvPath, 'rb');
        $line = $handle !== false ? fgets($handle) : false;

        if ($handle !== false) {
            fclose($handle);
        }

        $header = $line === false ? [] : array_map(
            static fn (?string $name): string => trim((string) preg_replace('/^\x{FEFF}/u', '', (string) $name)),
            str_getcsv(rtrim($line, "\r\n"), ',', '"', ''),
        );

        if (array_diff(self::REQUIRED_COLUMNS, $header) !== []) {
            throw RegistryRequestFailed::invalidPayload();
        }

        return $header;
    }

    private function prepare(): void
    {
        $this->forget();

        $url = (string) config('registries.options.tfda.url', self::DEFAULT_URL);
        $document = $this->fetcher->get($this->source, $url);

        if (! str_starts_with($document->body, "PK\x03\x04")) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $zipPath = $document->toTemporaryFile('zip');
        $csvPath = (string) tempnam(sys_get_temp_dir(), 'tfda-csv-');
        $rowsPath = (string) tempnam(sys_get_temp_dir(), 'tfda-rows-');

        try {
            self::extractCsv($zipPath, $csvPath);
            $header = self::header($csvPath);
            $offsets = [];
            $output = fopen($rowsPath, 'wb');

            if ($output === false) {
                throw new RegistryRequestFailed('Could not write the prepared TFDA rows.');
            }

            foreach ((new CsvReader('UTF-8'))->rows($csvPath) as $index => $row) {
                $id = trim((string) ($row[self::ID_COLUMN] ?? ''));
                $offsets[$id !== '' ? $id : "row:{$index}"][] = (int) ftell($output);
                fwrite($output, json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
            }

            fclose($output);
        } catch (\Throwable $exception) {
            @unlink($rowsPath);

            throw $exception;
        } finally {
            @unlink($zipPath);
            @unlink($csvPath);
        }

        $this->prepared = ['url' => $document->url, 'header' => $header, 'rows' => $rowsPath, 'offsets' => $offsets];
    }

    private function forget(): void
    {
        if ($this->prepared !== null) {
            @unlink($this->prepared['rows']);
            $this->prepared = null;
        }
    }
}
