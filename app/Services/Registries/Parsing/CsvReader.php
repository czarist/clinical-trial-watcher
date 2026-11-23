<?php

declare(strict_types=1);

namespace App\Services\Registries\Parsing;

use App\Exceptions\RegistryRequestFailed;

/**
 * Reads a registry CSV export row by row as arrays keyed by the header, converting the file's
 * encoding (for example Shift_JIS or Big5) to UTF-8 and dropping a byte order mark.
 */
final readonly class CsvReader
{
    public function __construct(
        private string $encoding = 'UTF-8',
        private string $separator = ',',
    ) {}

    /**
     * @return \Generator<int, array<string, string|null>> rows keyed by header, numbered from 0
     */
    public function rows(string $path): \Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RegistryRequestFailed("Could not open {$path}.");
        }

        // Skip a UTF-8 byte order mark before parsing, so a quoted first header is read correctly.
        if (fread($handle, 3) !== "\u{FEFF}") {
            rewind($handle);
        }

        try {
            $header = null;
            $index = 0;

            while (($fields = fgetcsv($handle, null, $this->separator, '"', '')) !== false) {
                $fields = array_map($this->clean(...), $fields);

                if ($header === null) {
                    $header = array_map(static fn (?string $name): string => (string) $name, $fields);

                    continue;
                }

                if ($fields === [null]) {
                    continue;
                }

                $width = count($header);

                yield $index++ => array_combine($header, array_pad(array_slice($fields, 0, $width), $width, null));
            }
        } finally {
            fclose($handle);
        }
    }

    private function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (strtoupper($this->encoding) !== 'UTF-8') {
            $value = (string) mb_convert_encoding($value, 'UTF-8', $this->encoding);
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
