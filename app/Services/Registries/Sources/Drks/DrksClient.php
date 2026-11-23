<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Drks;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;

/**
 * Reads DRKS from its full JSON export, a ZIP holding one JSON array of every trial. The
 * export is downloaded once per run through the RegistryFetcher (its stored copy answers
 * repeated runs while it is unchanged), its JSON file is extracted to a temporary file and
 * read one record at a time. Page tokens are byte offsets into that file.
 *
 * Only records under CC BY 4.0 are returned (see isOpenLicensed()). DRKS has no
 * updated-since query, so incremental runs filter on lastUpdate locally.
 */
final class DrksClient implements StudyRegistryClient
{
    public const string DUMP_URL = 'https://drks.de/search/en/download/all-json';

    /** First day of the period whose records the terms of use publish under CC BY 4.0. */
    public const string OPEN_LICENCE_FROM = '2025-01-01';

    private ?string $jsonPath = null;

    private int $jsonSize = 0;

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
    ) {}

    public function __destruct()
    {
        if ($this->jsonPath !== null && is_file($this->jsonPath)) {
            @unlink($this->jsonPath);
        }
    }

    /**
     * Whether DRKS publishes the record under CC BY 4.0: its terms of use apply that licence
     * to studies registered from 2025 or last updated in or after 2025. The export has no
     * licence field, so the rule is applied to registrationDrks and lastUpdate.
     *
     * @param  array<string, mixed>  $record
     */
    public static function isOpenLicensed(array $record): bool
    {
        foreach (['registrationDrks', 'lastUpdate'] as $field) {
            $date = $record[$field] ?? null;

            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}/', $date) === 1 && substr($date, 0, 10) >= self::OPEN_LICENCE_FROM) {
                return true;
            }
        }

        return false;
    }

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        $this->ensureDump();
        $offset = $this->offsetFrom($pageToken);
        $since = $updatedSince?->toDateString();
        $studies = [];

        foreach ((new JsonArrayStream((string) $this->jsonPath))->elements($offset) as $end => $json) {
            $record = json_decode($json, true);

            if (! is_array($record) || array_is_list($record)) {
                throw RegistryRequestFailed::invalidPayload();
            }

            if (! self::isOpenLicensed($record)) {
                continue;
            }

            if ($since !== null && (! is_string($record['lastUpdate'] ?? null) || substr($record['lastUpdate'], 0, 10) < $since)) {
                continue;
            }

            $studies[] = $record;

            if (count($studies) >= $pageSize) {
                return new StudyPage($studies, $end.':'.$this->jsonSize);
            }
        }

        return new StudyPage($studies, null);
    }

    private function offsetFrom(?string $pageToken): int
    {
        if ($pageToken === null || $pageToken === '') {
            return 0;
        }

        if (preg_match('/^(\d+):(\d+)$/', $pageToken, $match) !== 1) {
            throw new RegistryRequestFailed("Invalid DRKS page token {$pageToken}.");
        }

        if ((int) $match[2] !== $this->jsonSize) {
            throw new RegistryRequestFailed('The DRKS export changed during the import; run the import again.');
        }

        return (int) $match[1];
    }

    /** Downloads the export once and extracts its JSON array to a temporary file. */
    private function ensureDump(): void
    {
        if ($this->jsonPath !== null) {
            return;
        }

        $document = $this->fetcher->get($this->source, (string) config('registries.options.drks.dump_url', self::DUMP_URL));

        if (! str_starts_with($document->body, "PK\x03\x04")) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $zipPath = $document->toTemporaryFile('zip');
        unset($document);

        try {
            $this->jsonPath = $this->extractJson($zipPath);
            $this->jsonSize = (int) filesize($this->jsonPath);
        } finally {
            @unlink($zipPath);
        }
    }

    private function extractJson(string $zipPath): string
    {
        $zip = new \ZipArchive;

        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw RegistryRequestFailed::invalidPayload();
        }

        try {
            $entry = null;
            $largest = -1;

            // The export holds DRKS_all_YYYYMMDD.json next to small text files.
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if ($stat !== false && preg_match('/\.json$/i', $stat['name']) === 1 && $stat['size'] > $largest) {
                    $entry = $stat['name'];
                    $largest = $stat['size'];
                }
            }

            $stream = $entry !== null ? $zip->getStream($entry) : false;

            if ($stream === false) {
                throw RegistryRequestFailed::invalidPayload();
            }

            $path = (string) tempnam(sys_get_temp_dir(), 'drks-');
            $target = fopen($path, 'wb');

            if ($target === false) {
                fclose($stream);
                throw new RegistryRequestFailed("Could not write {$path}.");
            }

            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);

            return $path;
        } finally {
            $zip->close();
        }
    }
}
