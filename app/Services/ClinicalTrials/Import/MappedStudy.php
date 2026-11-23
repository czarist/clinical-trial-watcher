<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

use App\Services\ClinicalTrials\Changes\TrackedFacts;

/**
 * A study payload translated to the application's schema.
 */
final readonly class MappedStudy
{
    /**
     * @param  string  $source  key of the registry the payload came from
     * @param  string  $sourceId  the study's identifier in that registry; with the source, it identifies the record
     * @param  array<string, mixed>  $attributes  columns of clinical_trials
     * @param  array<string, list<array<string, mixed>>>  $relations  current rows of each related list, keyed by relation name
     * @param  TrackedFacts|null  $facts  facts compared between imports to describe changes
     * @param  string|null  $nctId  the NCT number, only for ClinicalTrials.gov records
     * @param  RawDocument|null  $raw  the record as published, for registries without a JSON payload in raw_json
     */
    public function __construct(
        public string $source,
        public string $sourceId,
        public array $attributes,
        public array $relations,
        public ?TrackedFacts $facts = null,
        public ?string $nctId = null,
        public ?RawDocument $raw = null,
    ) {}

    /**
     * Hash of the normalized record: equal hashes mean nothing mapped changed, so the
     * import can skip rewriting the study. The raw payload is left out.
     */
    public function contentHash(): string
    {
        $attributes = $this->attributes;
        unset($attributes['raw_json']);

        return hash('sha256', json_encode(
            [$this->source, $this->sourceId, $this->nctId, $attributes, $this->relations, $this->facts?->toArray()],
            JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
    }
}
