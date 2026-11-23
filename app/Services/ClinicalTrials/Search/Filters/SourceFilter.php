<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search\Filters;

use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/**
 * Studies registered in one of the chosen registries: the record shown, or any other
 * registration of the same study, comes from it.
 */
final class SourceFilter implements TrialFilter
{
    #[\Override]
    public function apply(Builder $query, TrialSearchCriteria $criteria): void
    {
        if ($criteria->sources === []) {
            return;
        }

        $query->where(fn (Builder $query) => $query
            ->whereIn('clinical_trials.source', $criteria->sources)
            ->orWhereExists(fn ($duplicates) => $duplicates
                ->from('clinical_trials as duplicate')
                ->whereColumn('duplicate.canonical_trial_id', 'clinical_trials.id')
                ->whereIn('duplicate.source', $criteria->sources)));
    }
}
