<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\DigestStatus;
use App\Exceptions\PaidFeatureRequired;
use App\Http\Controllers\Controller;
use App\Http\Resources\DigestResource;
use App\Models\Digest;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\Entitlements;
use App\Services\Digests\DigestSamples;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Digest reports: the user's own editions, or a team's with ?team_id=, and the public samples.
 */
final class DigestController extends Controller
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $query = Digest::query()->where('status', DigestStatus::Generated);

        if ($request->filled('team_id')) {
            $team = Team::query()->findOrFail((int) $request->query('team_id'));
            abort_unless($user->can('view', $team), 403);

            if (! $this->entitlements->teamIsActive($team)) {
                throw PaidFeatureRequired::teamWorkspace();
            }

            $query->where('team_id', $team->id);
        } else {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('alert_id')) {
            $query->where('alert_id', (int) $request->query('alert_id'));
        }

        return DigestResource::collection($query->latest('period_end')->latest('id')->paginate(20));
    }

    public function show(Digest $digest): DigestResource
    {
        return (new DigestResource($digest))->withContent();
    }

    /**
     * Two sample editions with synthetic data, viewable before subscribing.
     */
    public function samples(DigestSamples $samples): JsonResponse
    {
        return response()->json(['data' => $samples->all()]);
    }
}
