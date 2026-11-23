<?php

declare(strict_types=1);

namespace App\Services\Registries\Parsing;

use App\Exceptions\RegistryRequestFailed;

/**
 * Reads the first worksheet of an XLSX workbook row by row as arrays keyed by the header
 * row, without loading the sheet into memory (ZipArchive + XMLReader). Shared and inline
 * strings are resolved; missing cells are null, placed by their column letters. Numbers are
 * returned as written (dates stay Excel serial numbers).
 */
final class XlsxReader
{
    private const string MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const string REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @return list<string> names of the header row
     *
     * @throws RegistryRequestFailed when the file is not an XLSX workbook
     */
    public function header(string $path): array
    {
        foreach ($this->rawRows($path, $this->sharedStrings($path)) as $cells) {
            return $this->headerFrom($cells);
        }

        return [];
    }

    /**
     * @return \Generator<int, array<string, string|null>> rows keyed by header, numbered from 0
     *
     * @throws RegistryRequestFailed when the file is not an XLSX workbook
     */
    public function rows(string $path): \Generator
    {
        $strings = $this->sharedStrings($path);
        $header = null;
        $index = 0;

        foreach ($this->rawRows($path, $strings) as $cells) {
            if ($header === null) {
                $header = $this->headerFrom($cells);

                continue;
            }

            $row = [];
            $filled = false;

            foreach ($header as $column => $name) {
                $value = $cells[$column] ?? null;
                $row[$name] = $value;
                $filled = $filled || $value !== null;
            }

            if ($filled) {
                yield $index++ => $row;
            }
        }
    }

    /**
     * @param  array<int, string|null>  $cells
     * @return list<string>
     */
    private function headerFrom(array $cells): array
    {
        if ($cells === []) {
            return [];
        }

        $header = [];

        for ($column = 0, $last = max(array_keys($cells)); $column <= $last; $column++) {
            $name = trim((string) preg_replace('/^\x{FEFF}/u', '', (string) ($cells[$column] ?? '')));
            $header[$column] = $name === '' ? 'COLUMN_'.($column + 1) : $name;
        }

        return $header;
    }

    /**
     * @param  list<string>  $strings
     * @return \Generator<int, array<int, string|null>> cells of each row by zero-based column
     */
    private function rawRows(string $path, array $strings): \Generator
    {
        $reader = $this->open($path, $this->firstSheet($path));

        try {
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'row') {
                    $row = $reader->expand();

                    if ($row instanceof \DOMElement) {
                        yield $this->cells($row, $strings);
                    }
                }
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<string>  $strings
     * @return array<int, string|null>
     */
    private function cells(\DOMElement $row, array $strings): array
    {
        $cells = [];
        $next = 0;

        foreach ($row->childNodes as $cell) {
            if (! $cell instanceof \DOMElement || $cell->localName !== 'c') {
                continue;
            }

            $reference = $cell->getAttribute('r');
            $column = $reference !== '' ? self::columnIndex($reference) : $next;
            $next = $column + 1;
            $type = $cell->getAttribute('t');

            $value = match ($type) {
                'inlineStr' => $this->textOf($cell, 'is'),
                's' => is_numeric($index = $this->childText($cell, 'v')) ? ($strings[(int) $index] ?? null) : null,
                'b' => $this->childText($cell, 'v') === '1' ? 'TRUE' : 'FALSE',
                default => $this->childText($cell, 'v'),
            };

            $value = $value !== null ? trim($value) : null;
            $cells[$column] = $value === '' ? null : $value;
        }

        return $cells;
    }

    /** Zero-based column index of a cell reference such as "AB12". */
    public static function columnIndex(string $reference): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $reference));
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private function childText(\DOMElement $cell, string $name): ?string
    {
        foreach ($cell->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === $name) {
                return $child->textContent;
            }
        }

        return null;
    }

    /** Text of a string item (<si> or <is>): every <t>, phonetic runs left out. */
    private function textOf(\DOMElement $parent, ?string $childName = null): ?string
    {
        if ($childName !== null) {
            foreach ($parent->childNodes as $child) {
                if ($child instanceof \DOMElement && $child->localName === $childName) {
                    return $this->textOf($child);
                }
            }

            return null;
        }

        $text = '';

        foreach ($parent->getElementsByTagNameNS(self::MAIN_NS, 't') as $node) {
            if (! $node->parentNode instanceof \DOMElement || $node->parentNode->localName !== 'rPh') {
                $text .= $node->textContent;
            }
        }

        return $text;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(string $path): array
    {
        $zip = $this->zip($path);
        $exists = $zip->locateName('xl/sharedStrings.xml') !== false;
        $zip->close();

        if (! $exists) {
            return [];
        }

        $reader = $this->open($path, 'xl/sharedStrings.xml');
        $strings = [];

        try {
            while ($reader->read() && ! ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si')) {
                // Skip to the first string item.
            }

            while ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si') {
                $item = $reader->expand();
                $strings[] = $item instanceof \DOMElement ? (string) $this->textOf($item) : '';

                if (! $reader->next('si')) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return $strings;
    }

    /** Path inside the archive of the workbook's first worksheet. */
    private function firstSheet(string $path): string
    {
        $zip = $this->zip($path);

        try {
            $workbook = $zip->getFromName('xl/workbook.xml');
            $relations = $zip->getFromName('xl/_rels/workbook.xml.rels');

            if ($workbook === false) {
                throw new RegistryRequestFailed('The file is not an XLSX workbook.');
            }

            $document = new \DOMDocument;

            if (@$document->loadXML($workbook, LIBXML_NONET) === false) {
                throw new RegistryRequestFailed('The XLSX workbook is malformed.');
            }

            $sheet = $document->getElementsByTagNameNS(self::MAIN_NS, 'sheet')->item(0);
            $relationId = $sheet instanceof \DOMElement ? $sheet->getAttributeNS(self::REL_NS, 'id') : '';
            $target = null;

            if ($relationId !== '' && $relations !== false) {
                $rels = new \DOMDocument;

                if (@$rels->loadXML($relations, LIBXML_NONET) !== false) {
                    foreach ($rels->getElementsByTagName('Relationship') as $relation) {
                        if ($relation->getAttribute('Id') === $relationId) {
                            $target = $relation->getAttribute('Target');
                        }
                    }
                }
            }

            $target = match (true) {
                $target === null || $target === '' => 'xl/worksheets/sheet1.xml',
                str_starts_with($target, '/') => ltrim($target, '/'),
                default => 'xl/'.$target,
            };

            if ($zip->locateName($target) === false) {
                throw new RegistryRequestFailed('The XLSX workbook has no worksheet.');
            }

            return $target;
        } finally {
            $zip->close();
        }
    }

    private function zip(string $path): \ZipArchive
    {
        $zip = new \ZipArchive;

        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new RegistryRequestFailed('The file is not an XLSX workbook.');
        }

        return $zip;
    }

    private function open(string $path, string $entry): \XMLReader
    {
        $reader = \XMLReader::open('zip://'.$path.'#'.$entry, null, LIBXML_NONET | LIBXML_COMPACT);

        if ($reader === false) {
            throw new RegistryRequestFailed("Could not read {$entry} of the XLSX workbook.");
        }

        return $reader;
    }
}
