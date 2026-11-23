<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TeamInvitationResource;
use App\Http\Resources\TeamResource;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Teams\TeamService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Invitations addressed to the current user's email, which they accept or decline.
 */
final class TeamInvitationController extends Controller
{
    public function __construct(private readonly TeamService $teams) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return TeamInvitationResource::collection(
            TeamInvitation::query()->where('email', mb_strtolower($user->email))->with('team.owner')->latest('id')->get(),
        );
    }

    public function accept(Request $request, TeamInvitation $invitation): TeamResource
    {
        /** @var User $user */
        $user = $request->user();
        $membership = $this->teams->accept($invitation, $user);

        return new TeamResource($membership->team->load(['owner', 'members']));
    }

    public function decline(Request $request, TeamInvitation $invitation): Response
    {
        /** @var User $user */
        $user = $request->user();
        $this->teams->decline($invitation, $user);

        return response()->noContent();
    }
}
