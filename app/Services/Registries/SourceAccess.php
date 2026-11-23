<?php

declare(strict_types=1);

namespace App\Services\Registries;

use App\Enums\AccessMethod;

/**
 * The legal review of a registry, recorded before its importer ships (see
 * docs/registries-evaluation.md). Requests to a registry whose review does not allow
 * automated access are refused: ask the registry for permission or an export instead.
 */
final readonly class SourceAccess
{
    /**
     * @param  string  $termsUrl  terms of use or licence that were reviewed
     * @param  string  $licence  short description of what the terms allow
     * @param  string|null  $attribution  text to show where the registry's data appears
     * @param  string  $reviewedOn  date of the review (Y-m-d)
     * @param  bool  $automatedAccessAllowed  whether the terms and robots.txt allow this importer's requests
     * @param  int|null  $minIntervalMs  minimum delay between two requests, when stricter than the default
     * @param  bool  $commercialUse  whether the terms allow the data in paid features; when false the records
     *                               are shown for free with attribution but kept out of AI digests
     */
    public function __construct(
        public AccessMethod $method,
        public string $termsUrl,
        public string $licence,
        public ?string $attribution,
        public string $reviewedOn,
        public bool $automatedAccessAllowed,
        public ?int $minIntervalMs = null,
        public bool $commercialUse = true,
    ) {}
}
