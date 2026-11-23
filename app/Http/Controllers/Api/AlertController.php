<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AlertFrequency;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAlertRequest;
use App\Http\Resources\AlertMatchResource;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\User;
use App\Services\Alerts\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class AlertController extends Controller
{
    public function __construct(private readonly AlertService $alerts) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return AlertResource::collection(
            $user->alerts()->withCount('pendingMatches')->latest('id')->paginate(50),
        )->additional(['meta' => ['alerts_limit' => $this->alerts->limitFor($user)]]);
    }

    public function store(SaveAlertRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $changes = $request->changes();

        $alert = $this->alerts->create(
            $user,
            $changes['name'],
            $changes['criteria'],
            $changes['frequency'] ?? AlertFrequency::Daily,
            $changes['notify_status_changes'] ?? true,
        );

        return (new AlertResource($alert->refresh()))->response()->setStatusCode(201);
    }

    public function show(Alert $alert): AlertResource
    {
        return new AlertResource($alert->loadCount('pendingMatches'));
    }

    public function update(SaveAlertRequest $request, Alert $alert): AlertResource
    {
        return new AlertResource($this->alerts->update($alert, $request->changes()));
    }

    public function destroy(Alert $alert): Response
    {
        $this->alerts->delete($alert);

        return response()->noContent();
    }

    public function matches(Alert $alert): AnonymousResourceCollection
    {
        return AlertMatchResource::collection(
            $alert->matches()->with(['trial', 'change'])->latest('id')->paginate(20),
        );
    }
}
