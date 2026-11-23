<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search;

use App\Models\ClinicalTrials\ClinicalTrial;
use App\Services\ClinicalTrials\Search\Filters\FullTextFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Allowed sort orders for the search endpoint. A leading "-" means descending.
 */
enum TrialSort: string
{
    case LastUpdateDesc = '-last_update_post_date';
    case LastUpdateAsc = 'last_update_post_date';
    case StartDateDesc = '-start_date';
    case StartDateAsc = 'start_date';
    case FirstPostedDesc = '-study_first_post_date';
    case FirstPostedAsc = 'study_first_post_date';
    case TitleAsc = 'brief_title';
    case TitleDesc = '-brief_title';
    /** Full-text relevance; falls back to the last update when there is no text query. */
    case Relevance = 'relevance';

    /**
     * @param  Builder<ClinicalTrial>  $query
     */
    public function apply(Builder $query, TrialSearchCriteria $criteria): void
    {
        if ($this === self::Relevance) {
            if ($criteria->q !== null && $query->getConnection()->getDriverName() === 'mysql') {
                $columns = implode(', ', array_map(fn (string $c): string => "clinical_trials.{$c}", FullTextFilter::COLUMNS));
                $query->orderByRaw("MATCH ({$columns}) AGAINST (?) DESC", [$criteria->q]);
            } else {
                self::LastUpdateDesc->apply($query, $criteria);

                return;
            }
        } else {
            $query->orderBy('clinical_trials.'.ltrim($this->value, '-'), str_starts_with($this->value, '-') ? 'desc' : 'asc');
        }

        $query->orderBy('clinical_trials.id', 'desc');
    }
}
