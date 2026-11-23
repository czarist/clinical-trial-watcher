<?php

declare(strict_types=1);

namespace App\Services\Registries\Sources\Drks;

use App\Exceptions\RegistryRequestFailed;

/**
 * Reads the objects of a large top-level JSON array one at a time, so a dump of hundreds of
 * megabytes is never decoded at once. Each element is yielded as its JSON text, keyed by the
 * byte offset just after it; passing that offset back resumes reading at the next element.
 */
final readonly class JsonArrayStream
{
    private const int CHUNK_BYTES = 65_536;

    public function __construct(
        private string $path,
        private int $maxElementBytes = 16_777_216,
    ) {}

    /**
     * @param  int  $offset  0 for the start of the file, or an offset yielded by an earlier read
     * @return \Generator<int, string> JSON text of each object, keyed by the byte offset after it
     *
     * @throws RegistryRequestFailed when the file is not a JSON array of objects
     */
    public function elements(int $offset = 0): \Generator
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new RegistryRequestFailed("Could not open {$this->path}.");
        }

        try {
            if ($offset > 0 && fseek($handle, $offset) !== 0) {
                throw RegistryRequestFailed::invalidPayload();
            }

            $buffer = '';
            $base = $offset;   // file offset of $buffer[0]
            $pos = 0;
            $state = $offset === 0 ? 'open' : 'separator';
            $start = 0;
            $depth = 0;
            $inString = false;

            while (true) {
                if ($pos >= strlen($buffer)) {
                    if ($state !== 'element') {
                        $base += $pos;
                        $buffer = '';
                        $pos = 0;
                    }

                    if (! $this->read($handle, $buffer)) {
                        throw RegistryRequestFailed::invalidPayload();
                    }

                    if ($state === 'element' && strlen($buffer) - $start > $this->maxElementBytes) {
                        throw RegistryRequestFailed::invalidPayload();
                    }

                    continue;
                }

                if ($state !== 'element') {
                    if ($state === 'open' && $base === 0 && $pos === 0 && str_starts_with($buffer, "\xEF\xBB\xBF")) {
                        $pos = 3;
                    }

                    $pos += strspn($buffer, " \t\r\n", $pos);

                    if ($pos >= strlen($buffer)) {
                        continue;
                    }

                    $char = $buffer[$pos];

                    if ($state === 'open') {
                        $char === '[' || throw RegistryRequestFailed::invalidPayload();
                        $pos++;
                        $state = 'first';

                        continue;
                    }

                    if ($char === ']' && ($state === 'first' || $state === 'separator')) {
                        return;
                    }

                    if ($state === 'separator') {
                        $char === ',' || throw RegistryRequestFailed::invalidPayload();
                        $pos++;
                        $state = 'value';

                        continue;
                    }

                    // 'first' or 'value': an object must start here.
                    $char === '{' || throw RegistryRequestFailed::invalidPayload();
                    $buffer = substr($buffer, $pos);
                    $base += $pos;
                    $pos = 0;
                    $start = 0;
                    $depth = 0;
                    $inString = false;
                    $state = 'element';
                }

                if ($inString) {
                    $pos += strcspn($buffer, '"\\', $pos);

                    if ($pos >= strlen($buffer)) {
                        continue;
                    }

                    if ($buffer[$pos] === '\\') {
                        if ($pos + 1 >= strlen($buffer) && ! $this->read($handle, $buffer)) {
                            throw RegistryRequestFailed::invalidPayload();
                        }

                        $pos += 2;

                        continue;
                    }

                    $inString = false;
                    $pos++;

                    continue;
                }

                $pos += strcspn($buffer, '{}[]"', $pos);

                if ($pos >= strlen($buffer)) {
                    continue;
                }

                $char = $buffer[$pos++];

                if ($char === '"') {
                    $inString = true;
                } elseif ($char === '{' || $char === '[') {
                    $depth++;
                } elseif (--$depth === 0) {
                    yield $base + $pos => substr($buffer, $start, $pos - $start);

                    $buffer = substr($buffer, $pos);
                    $base += $pos;
                    $pos = 0;
                    $state = 'separator';
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function read($handle, string &$buffer): bool
    {
        if (feof($handle)) {
            return false;
        }

        $chunk = fread($handle, self::CHUNK_BYTES);

        if ($chunk === false || $chunk === '') {
            return false;
        }

        $buffer .= $chunk;

        return true;
    }
}
