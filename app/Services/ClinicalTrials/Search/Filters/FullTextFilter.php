<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search\Filters;

use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/**
 * Full-text search on titles and brief summary (MySQL FULLTEXT index, natural language mode).
 */
final class FullTextFilter implements TrialFilter
{
    public const array COLUMNS = ['brief_title', 'official_title', 'brief_summary'];

    #[\Override]
    public function apply(Builder $query, TrialSearchCriteria $criteria): void
    {
        if ($criteria->q === null) {
            return;
        }

        if ($query->getConnection()->getDriverName() === 'mysql') {
            $query->whereFullText(array_map(fn (string $column): string => "clinical_trials.{$column}", self::COLUMNS), $criteria->q);

            return;
        }

        $pattern = LikePattern::contains($criteria->q);
        $query->where(function (Builder $query) use ($pattern): void {
            foreach (self::COLUMNS as $column) {
                $query->orWhere("clinical_trials.{$column}", 'like', $pattern);
            }
        });
    }
}
