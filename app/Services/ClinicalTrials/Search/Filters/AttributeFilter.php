<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search\Filters;

use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/**
 * Status, study type, sponsor and results filters on clinical_trials columns.
 */
final class AttributeFilter implements TrialFilter
{
    #[\Override]
    public function apply(Builder $query, TrialSearchCriteria $criteria): void
    {
        if ($criteria->statuses !== []) {
            $query->whereIn('clinical_trials.overall_status', $criteria->statuses);
        }

        if ($criteria->studyTypes !== []) {
            $query->whereIn('clinical_trials.study_type', $criteria->studyTypes);
        }

        if ($criteria->sponsor !== null) {
            $query->where('clinical_trials.sponsor_name', 'like', LikePattern::contains($criteria->sponsor));
        }

        if ($criteria->hasResults !== null) {
            $query->where('clinical_trials.has_results', $criteria->hasResults);
        }
    }
}
