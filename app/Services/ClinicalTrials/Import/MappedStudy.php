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
     * @param  array<string, mixed>  $attributes  columns of clinical_trials
     * @param  array<string, list<array<string, mixed>>>  $relations  current rows of each related list, keyed by relation name
     * @param  TrackedFacts|null  $facts  facts compared between imports to describe changes
     */
    public function __construct(
        public string $nctId,
        public array $attributes,
        public array $relations,
        public ?TrackedFacts $facts = null,
    ) {}
}
