<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search\Filters;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrows a study query by one aspect of the search criteria. A filter does nothing when
 * its criteria are empty.
 */
interface TrialFilter
{
    /**
     * @param  Builder<ClinicalTrial>  $query
     */
    public function apply(Builder $query, TrialSearchCriteria $criteria): void;
}
