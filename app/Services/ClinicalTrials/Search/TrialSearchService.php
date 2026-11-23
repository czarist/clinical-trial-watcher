<?php

declare(strict_types=1);

namespace App\Services\ClinicalTrials\Search;

use App\Models\ClinicalTrials\ClinicalTrial;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class TrialSearchService
{
    public const int MAX_PER_PAGE = 100;

    public function __construct(private TrialQuery $query) {}

    /**
     * @return LengthAwarePaginator<int, ClinicalTrial>
     */
    public function search(TrialSearchCriteria $criteria, TrialSort $sort = TrialSort::LastUpdateDesc, int $perPage = 20): LengthAwarePaginator
    {
        $query = $this->query->build($criteria)
            ->with('conditions')
            ->withCount('locations');

        $sort->apply($query, $criteria);

        return $query->paginate(min(max($perPage, 1), self::MAX_PER_PAGE))->withQueryString();
    }

    public function findByNctId(string $nctId): ?ClinicalTrial
    {
        return ClinicalTrial::query()
            ->where('nct_id', $nctId)
            ->with([
                ...ClinicalTrial::SYNCED_RELATIONS,
                'identifiers',
                'canonical:id,source,source_id,nct_id',
                'duplicates:id,source,source_id,nct_id,canonical_trial_id',
                'trialChanges' => fn ($query) => $query->latest('id')->limit(50),
            ])
            ->first();
    }
}
