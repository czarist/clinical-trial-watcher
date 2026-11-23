<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Search\Filters\TrialFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Builds the study query for a set of criteria by applying every registered filter.
 */
final readonly class TrialQuery
{
    /**
     * @param  iterable<TrialFilter>  $filters
     */
    public function __construct(private iterable $filters) {}

    /**
     * @return Builder<ClinicalTrial>
     */
    public function build(TrialSearchCriteria $criteria): Builder
    {
        $query = ClinicalTrial::query();

        foreach ($this->filters as $filter) {
            $filter->apply($query, $criteria);
        }

        return $query;
    }
}
