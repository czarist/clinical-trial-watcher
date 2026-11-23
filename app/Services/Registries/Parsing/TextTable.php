<?php

declare(strict_types=1);

namespace App\Services\Registries\Parsing;

/**
 * Splits a fixed-width table, as pdftotext -layout prints it, into rows.
 *
 * Columns start where the header's labels start. A line whose first column is empty
 * continues the cells of the previous row (wrapped text); a line that repeats the header
 * (a new page) is skipped; blank lines and lines before the first header are ignored.
 */
final readonly class TextTable
{
    /**
     * @param  list<string>  $columns  header labels, in order, as printed in the document
     */
    public function __construct(private array $columns) {}

    /**
     * @return list<array<string, string|null>> rows keyed by the header labels
     */
    public function parse(string $text): array
    {
        $rows = [];
        $offsets = null;

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = str_replace("\f", '', $line);
            $headerOffsets = $this->headerOffsets($line);

            if ($headerOffsets !== null) {
                $offsets = $headerOffsets;

                continue;
            }

            if ($offsets === null || trim($line) === '') {
                continue;
            }

            $cells = $this->cells($line, $offsets);

            if ($cells[$this->columns[0]] === null && $rows !== []) {
                $last = array_key_last($rows);

                foreach ($cells as $column => $value) {
                    if ($value !== null) {
                        $rows[$last][$column] = trim(($rows[$last][$column] ?? '').' '.$value);
                    }
                }

                continue;
            }

            if (array_filter($cells) !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    /**
     * @return list<int>|null character offset of each column when the line is the header
     */
    private function headerOffsets(string $line): ?array
    {
        $offsets = [];
        $from = 0;

        foreach ($this->columns as $label) {
            $position = mb_stripos($line, $label, $from);

            if ($position === false) {
                return null;
            }

            $offsets[] = $position;
            $from = $position + mb_strlen($label);
        }

        return $offsets;
    }

    /**
     * @param  list<int>  $offsets
     * @return array<string, string|null>
     */
    private function cells(string $line, array $offsets): array
    {
        $cells = [];

        foreach ($this->columns as $index => $label) {
            $start = $index === 0 ? 0 : $offsets[$index];
            $length = isset($offsets[$index + 1]) ? $offsets[$index + 1] - $start : null;
            $value = trim(mb_substr($line, $start, $length));
            $cells[$label] = $value === '' ? null : $value;
        }

        return $cells;
    }
}
