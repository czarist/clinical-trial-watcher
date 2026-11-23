<?php

declare(strict_types=1);

namespace App\Services\Registries\Parsing;

use Illuminate\Support\Facades\Process;

/**
 * Uses poppler's pdftotext -layout (installed in the app image), which keeps the columns of
 * a table aligned in the text.
 */
final readonly class PopplerPdfTextExtractor implements PdfTextExtractor
{
    public function __construct(private int $timeoutSeconds = 120) {}

    #[\Override]
    public function extract(string $path): string
    {
        $result = Process::timeout($this->timeoutSeconds)->run(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-']);

        if ($result->failed()) {
            throw new \RuntimeException('pdftotext could not read the PDF: '.trim($result->errorOutput()));
        }

        return $result->output();
    }
}
