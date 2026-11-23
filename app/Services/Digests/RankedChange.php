<?php

declare(strict_types=1);

namespace App\Services\Digests;

use App\Models\ClinicalTrials\ClinicalTrialChange;

/**
 * A matching change with its relevance to one saved search, computed by the backend.
 */
final readonly class RankedChange
{
    /**
     * @param  list<string>  $reasons  why the change is relevant to the saved search, in the digest language
     */
    public function __construct(
        public ClinicalTrialChange $change,
        public int $score,
        public array $reasons,
    ) {}
}
