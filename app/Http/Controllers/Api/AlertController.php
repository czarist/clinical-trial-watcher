<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\AlertFrequency;
use App\Exceptions\PaidFeatureRequired;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveAlertRequest;
use App\Http\Resources\AlertMatchResource;
use App\Http\Resources\AlertResource;
use App\Models\Alert;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerts\AlertService;
use App\Services\Billing\Entitlements;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class AlertController extends Controller
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * The user's personal saved searches, or a team's shared ones with ?team_id=.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->requestedTeam($request, $user);

        $query = $team !== null ? $team->alerts() : $user->alerts()->personal();

        return AlertResource::collection(
            $query->withCount('pendingMatches')->latest('id')->paginate(50),
        )->additional(['meta' => [
            'alerts_limit' => $this->alerts->limit(),
            'ai_digests_available' => $team !== null || $this->entitlements->hasPersonalAiDigests($user),
        ]]);
    }

    public function store(SaveAlertRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $changes = $request->changes();
        $changes['frequency'] ??= AlertFrequency::Daily;

        $alert = $this->alerts->create($user, $request->team(), $changes);

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

    private function requestedTeam(Request $request, User $user): ?Team
    {
        if (! $request->filled('team_id')) {
            return null;
        }

        $team = Team::query()->findOrFail((int) $request->query('team_id'));
        abort_unless($user->can('view', $team), 403);

        if (! $this->entitlements->teamIsActive($team)) {
            throw PaidFeatureRequired::teamWorkspace();
        }

        return $team;
    }
}
