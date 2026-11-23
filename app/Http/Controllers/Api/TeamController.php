<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InviteTeamMemberRequest;
use App\Http\Requests\SaveTeamRequest;
use App\Http\Resources\TeamInvitationResource;
use App\Http\Resources\TeamResource;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Teams\TeamService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Team AI workspaces. Members see their teams; the owner manages members and invitations.
 */
final class TeamController extends Controller
{
    public function __construct(private readonly TeamService $teams) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return TeamResource::collection($user->teams()->with('owner')->orderBy('teams.name')->get());
    }

    public function store(SaveTeamRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->teams->create($user, (string) $request->validated('name'));

        return (new TeamResource($this->detailed($team)))->response()->setStatusCode(201);
    }

    public function show(Team $team): TeamResource
    {
        return new TeamResource($this->detailed($team));
    }

    public function update(SaveTeamRequest $request, Team $team): TeamResource
    {
        return new TeamResource($this->detailed($this->teams->rename($team, (string) $request->validated('name'))));
    }

    public function destroy(Team $team): Response
    {
        $this->teams->delete($team);

        return response()->noContent();
    }

    public function invite(InviteTeamMemberRequest $request, Team $team): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $invitation = $this->teams->invite($team, $user, $request->invitee());

        return (new TeamInvitationResource($invitation->load('team')))->response()->setStatusCode(201);
    }

    public function revokeInvitation(Team $team, TeamInvitation $invitation): Response
    {
        abort_unless($invitation->team_id === $team->id, 404);
        $this->teams->revoke($invitation);

        return response()->noContent();
    }

    /**
     * The owner removes a member, or a member removes themself (leaves the team).
     */
    public function removeMember(Request $request, Team $team, User $member): Response
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($team->isOwnedBy($user) || $user->is($member), 403);
        abort_unless($team->hasMember($member), 404);

        $this->teams->removeMember($team, $member);

        return response()->noContent();
    }

    /**
     * The current member's own settings in the team: whether they receive the team's digests.
     */
    public function updateMembership(Request $request, Team $team): TeamResource
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate(['receives_digests' => ['required', 'boolean']]);

        $membership = $team->membershipOf($user);
        abort_if($membership === null, 403);
        $this->teams->setReceivesDigests($membership, (bool) $validated['receives_digests']);

        return new TeamResource($this->detailed($team));
    }

    private function detailed(Team $team): Team
    {
        return $team->load(['owner', 'members', 'invitations']);
    }
}
