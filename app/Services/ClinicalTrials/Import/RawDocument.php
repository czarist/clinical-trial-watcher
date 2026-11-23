<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

/**
 * The record as the registry published it (an XML element, a CSV row, an HTML page, the text
 * of a PDF table row), kept with the study so fields can be remapped without fetching again.
 */
final readonly class RawDocument
{
    /**
     * @param  string  $format  json, xml, csv, txt, html or pdf
     * @param  string|null  $url  where the record (or the file that contains it) was read
     */
    public function __construct(
        public string $format,
        public string $content,
        public ?string $url = null,
    ) {}
}
