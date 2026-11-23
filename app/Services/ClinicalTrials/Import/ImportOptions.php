<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Import;

use Carbon\CarbonImmutable;

final readonly class ImportOptions
{
    /**
     * @param  bool  $full  read the whole registry instead of studies updated since the last successful run
     * @param  CarbonImmutable|null  $updatedSince  explicit lower bound for LastUpdatePostDate; overrides the last run
     * @param  int|null  $maxPages  stop after this many pages; the run is then recorded as partial
     */
    public function __construct(
        public int $pageSize = 100,
        public bool $full = false,
        public ?CarbonImmutable $updatedSince = null,
        public ?int $maxPages = null,
    ) {}
}
