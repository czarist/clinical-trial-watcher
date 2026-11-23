<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Orphanet;

use App\Exceptions\RegistryRequestFailed;
use App\Services\ClinicalTrials\Import\StudyPage;
use App\Services\ClinicalTrials\Import\StudyRegistryClient;
use App\Services\Registries\Fetching\RegistryFetcher;
use App\Services\Registries\RegistrySource;
use Carbon\CarbonInterface;
use Generator;
use XMLReader;

/**
 * Reads the Orphadata clinical trials XML dump (root `JDBOR`, trials in
 * `ClinicalTrialList/ClinicalTrial`). The dump is downloaded once per run through the
 * RegistryFetcher (its stored copy answers repeated runs while it is unchanged), written to a
 * temporary file and streamed with XMLReader, one trial element at a time. Page tokens are the
 * index of the next trial and a hash of the file, so a dump replaced during a run is detected.
 *
 * The dump is a full delivery without an updated-since query: `updatedSince` is ignored and
 * unchanged trials are recognised by the importer's content hash.
 */
final class OrphanetClient implements StudyRegistryClient
{
    private ?string $xmlPath = null;

    private string $xmlHash = '';

    public function __construct(
        private readonly RegistryFetcher $fetcher,
        private readonly RegistrySource $source,
        private readonly string $dumpUrl,
    ) {}

    public function __destruct()
    {
        if ($this->xmlPath !== null && is_file($this->xmlPath)) {
            @unlink($this->xmlPath);
        }
    }

    public function fetchPage(int $pageSize, ?string $pageToken = null, ?CarbonInterface $updatedSince = null): StudyPage
    {
        $this->ensureDump();
        $offset = $this->offsetFrom($pageToken);
        $limit = max(1, $pageSize);
        $studies = [];

        foreach (self::trials((string) $this->xmlPath, $offset) as $index => $trial) {
            if (count($studies) >= $limit) {
                return new StudyPage($studies, $index.':'.$this->xmlHash);
            }

            $studies[] = $trial;
        }

        return new StudyPage($studies, null);
    }

    /**
     * The trials of a dump file from the given index, as `id` (the trial id attribute) and `xml`
     * (the element as published), keyed by their index in the file.
     *
     * @return Generator<int, array{id: string|null, xml: string}>
     *
     * @throws RegistryRequestFailed when the file is not an Orphadata clinical trials dump
     */
    public static function trials(string $path, int $offset = 0): Generator
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader;

        try {
            if (! @$reader->open($path, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw RegistryRequestFailed::invalidPayload();
            }

            self::moveToTrialList($reader);

            if ($reader->isEmptyElement) {
                return;
            }

            // The first element inside the list.
            while (true) {
                if (! $reader->read()) {
                    throw self::malformed();
                }

                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === 1) {
                    return;
                }

                if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === 2) {
                    break;
                }
            }

            for ($index = 0; ; $index++) {
                if ($reader->name !== 'ClinicalTrial') {
                    throw new RegistryRequestFailed("The Orphanet dump lists an unexpected <{$reader->name}> element; the format may have changed.");
                }

                if ($index >= $offset) {
                    $id = $reader->getAttribute('id');
                    $xml = $reader->readOuterXml();

                    if ($xml === '') {
                        throw self::malformed();
                    }

                    yield $index => ['id' => $id, 'xml' => $xml];
                }

                // The next sibling element, or the end of the list.
                do {
                    if (! $reader->next()) {
                        throw self::malformed();
                    }

                    if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth <= 1) {
                        return;
                    }
                } while ($reader->nodeType !== XMLReader::ELEMENT);
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * Positions the reader on `ClinicalTrialList`, a direct child of the `JDBOR` root.
     *
     * @throws RegistryRequestFailed when the root or the list is missing
     */
    private static function moveToTrialList(XMLReader $reader): void
    {
        while (@$reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            if ($reader->depth === 0 && $reader->name !== 'JDBOR') {
                throw RegistryRequestFailed::invalidPayload();
            }

            if ($reader->depth === 1 && $reader->name === 'ClinicalTrialList') {
                return;
            }
        }

        throw libxml_get_last_error() !== false ? self::malformed() : RegistryRequestFailed::invalidPayload();
    }

    private static function malformed(): RegistryRequestFailed
    {
        return new RegistryRequestFailed('The Orphanet dump is not well-formed XML; the delivery may be truncated.');
    }

    private function offsetFrom(?string $pageToken): int
    {
        if ($pageToken === null || $pageToken === '') {
            return 0;
        }

        if (preg_match('/^(\d+):([0-9a-f]{64})$/', $pageToken, $match) !== 1) {
            throw new RegistryRequestFailed("Invalid Orphanet page token {$pageToken}.");
        }

        if ($match[2] !== $this->xmlHash) {
            throw new RegistryRequestFailed('The Orphanet dump changed during the import; run the import again.');
        }

        return (int) $match[1];
    }

    /** Downloads the dump once and writes it to a temporary file. */
    private function ensureDump(): void
    {
        if ($this->xmlPath !== null) {
            return;
        }

        if (trim($this->dumpUrl) === '') {
            throw new RegistryRequestFailed('The Orphanet clinical trials dump is delivered only under a Data Transfer Agreement or an Orphanet-AISBL service contract; set REGISTRY_ORPHANET_DUMP_URL to the delivery URL of the National or Multinational Clinical Trials XML once the contract is signed.');
        }

        $document = $this->fetcher->get($this->source, $this->dumpUrl);

        if (! str_starts_with(ltrim((string) preg_replace('/^\xEF\xBB\xBF/', '', substr($document->body, 0, 512))), '<')) {
            throw RegistryRequestFailed::invalidPayload();
        }

        $this->xmlPath = $document->toTemporaryFile('xml');
        unset($document);
        $this->xmlHash = (string) hash_file('sha256', $this->xmlPath);
    }
}
