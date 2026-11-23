<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SavePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Services\Admin\PlanAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class PlanController extends Controller
{
    public function __construct(private readonly PlanAdministration $plans) {}

    /**
     * Every plan, legacy plans included, so their Stripe prices can be checked.
     */
    public function index(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::query()->orderBy('is_legacy')->orderBy('price')->get());
    }

    public function store(SavePlanRequest $request): JsonResponse
    {
        /** @var array{name: string, price: float|string, billing_cycle: string, stripe_price_id?: string|null, trial_days?: int} $data */
        $data = $request->validated();

        return (new PlanResource($this->plans->create($data)))->response()->setStatusCode(201);
    }

    public function update(SavePlanRequest $request, Plan $plan): PlanResource
    {
        return new PlanResource($this->plans->update($plan, $request->validated()));
    }

    public function destroy(Plan $plan): Response
    {
        $this->plans->delete($plan);

        return response()->noContent();
    }
}
