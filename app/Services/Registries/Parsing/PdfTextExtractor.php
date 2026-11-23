<?php

declare(strict_types=1);

namespace App\Services\Registries\Parsing;

/**
 * Extracts the text of a PDF keeping its layout, so tables can be split into columns.
 */
interface PdfTextExtractor
{
    /**
     * @throws \RuntimeException when the file is not a readable PDF
     */
    public function extract(string $path): string;
}
