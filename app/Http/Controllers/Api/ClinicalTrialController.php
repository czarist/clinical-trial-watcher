<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SearchTrialsRequest;
use App\Http\Resources\ClinicalTrialResource;
use App\Http\Resources\ClinicalTrialSummaryResource;
use App\Services\ClinicalTrials\Search\TrialSearchService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ClinicalTrialController extends Controller
{
    public function __construct(private readonly TrialSearchService $search) {}

    public function index(SearchTrialsRequest $request): AnonymousResourceCollection
    {
        $results = $this->search->search($request->criteria(), $request->sort(), $request->perPage());

        return ClinicalTrialSummaryResource::collection($results)
            ->additional(['meta' => ['disclaimer' => ClinicalTrialSummaryResource::DISCLAIMER]]);
    }

    public function show(string $nctId): ClinicalTrialResource
    {
        $trial = $this->search->findByNctId(strtoupper($nctId));

        abort_if($trial === null, 404, 'Study not found.');

        return new ClinicalTrialResource($trial);
    }
}
