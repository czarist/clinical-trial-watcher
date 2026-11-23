<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search\Filters;

use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inclusive date range on one clinical_trials date column.
 */
final readonly class DateRangeFilter implements TrialFilter
{
    /**
     * @param  \Closure(TrialSearchCriteria): array{0: ?string, 1: ?string}  $range
     */
    public function __construct(
        private string $column,
        private \Closure $range,
    ) {}

    #[\Override]
    public function apply(Builder $query, TrialSearchCriteria $criteria): void
    {
        [$from, $to] = ($this->range)($criteria);

        if ($from !== null) {
            $query->whereDate("clinical_trials.{$this->column}", '>=', $from);
        }

        if ($to !== null) {
            $query->whereDate("clinical_trials.{$this->column}", '<=', $to);
        }
    }
}
