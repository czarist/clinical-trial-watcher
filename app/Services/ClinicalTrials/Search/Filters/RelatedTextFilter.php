<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search\Filters;

use App\Services\ClinicalTrials\Search\TrialSearchCriteria;
use Illuminate\Database\Eloquent\Builder;

/**
 * Keeps studies with a related row whose column contains the given text,
 * for example a condition, keyword or MeSH term.
 */
final readonly class RelatedTextFilter implements TrialFilter
{
    /**
     * @param  \Closure(TrialSearchCriteria): ?string  $value
     */
    public function __construct(
        private \Closure $value,
        private string $relation,
        private string $column,
    ) {}

    #[\Override]
    public function apply(Builder $query, TrialSearchCriteria $criteria): void
    {
        $text = ($this->value)($criteria);

        if ($text === null) {
            return;
        }

        $query->whereHas(
            $this->relation,
            fn (Builder $related) => $related->where($this->column, 'like', LikePattern::contains($text)),
        );
    }
}
