<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search\Filters;

use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lists each study once: records that are another registration of a study already shown
 * (they point to it through canonical_trial_id) are left out of searches and alert matching.
 */
final class CanonicalFilter implements TrialFilter
{
    #[\Override]
    public function apply(Builder $query, TrialSearchCriteria $criteria): void
    {
        $query->whereNull('clinical_trials.canonical_trial_id');
    }
}
