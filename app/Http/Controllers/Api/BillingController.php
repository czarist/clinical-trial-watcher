<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePlanRequest;
use App\Http\Resources\PlanResource;
use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\BillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class BillingController extends Controller
{
    public function __construct(private readonly BillingService $billing) {}

    public function plans(): AnonymousResourceCollection
    {
        return PlanResource::collection(Plan::query()->orderBy('price')->get());
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->billing->summary($user)]);
    }

    /**
     * Subscribes, upgrades, downgrades or returns to the free plan. When payment is needed,
     * the response contains the Stripe Checkout URL to open.
     */
    public function changePlan(ChangePlanRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $checkoutUrl = $this->billing->changePlan($user, $request->plan());

        return response()->json([
            'checkout_url' => $checkoutUrl,
            'data' => $this->billing->summary($user->refresh()),
        ]);
    }

    public function cancel(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->billing->cancel($user);

        return response()->json(['data' => $this->billing->summary($user->refresh())]);
    }

    public function resume(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->billing->resume($user);

        return response()->json(['data' => $this->billing->summary($user->refresh())]);
    }

    public function portal(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['url' => $this->billing->portalUrl($user)]);
    }
}
