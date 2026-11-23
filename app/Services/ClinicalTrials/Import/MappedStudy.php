<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

/**
 * A study payload translated to the application's schema.
 */
final readonly class MappedStudy
{
    /**
     * @param  array<string, mixed>  $attributes  columns of clinical_trials
     * @param  array<string, list<array<string, mixed>>>  $relations  current rows of each related list, keyed by relation name
     */
    public function __construct(
        public string $nctId,
        public array $attributes,
        public array $relations,
    ) {}
}
