<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search\Filters;

use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/**
 * Matches any of the requested phases. Phases are stored joined by ", " (e.g. "PHASE1, PHASE2"),
 * so each phase is matched as a whole list item; PHASE1 does not match EARLY_PHASE1.
 */
final class PhaseFilter implements TrialFilter
{
    #[\Override]
    public function apply(Builder $query, TrialSearchCriteria $criteria): void
    {
        if ($criteria->phases === []) {
            return;
        }

        $query->where(function (Builder $query) use ($criteria): void {
            foreach ($criteria->phases as $phase) {
                $query->orWhereRaw(
                    "CONCAT(', ', clinical_trials.phase, ',') LIKE ?",
                    ['%, '.addcslashes($phase, '\\%_').',%'],
                );
            }
        });
    }
}
