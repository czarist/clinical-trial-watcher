<?php

declare(strict_types=1);

namespace App\Services\Teams;

use App\Enums\TeamRole;
use App\Exceptions\PaidFeatureRequired;
use App\Exceptions\TeamActionFailed;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TeamMember;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\Billing\Entitlements;
use Illuminate\Database\ConnectionInterface;

/**
 * Team AI workspaces: one per Team AI subscriber, who invites registered users by email.
 * An invitation becomes a membership only when the invited user accepts it.
 */
final readonly class TeamService
{
    public function __construct(
        private Entitlements $entitlements,
        private ConnectionInterface $db,
    ) {}

    public function create(User $owner, string $name): Team
    {
        if (! $this->entitlements->canOwnTeam($owner)) {
            throw PaidFeatureRequired::teamWorkspace();
        }

        if ($owner->ownedTeam()->exists()) {
            throw TeamActionFailed::alreadyOwnsTeam();
        }

        return $this->db->transaction(function () use ($owner, $name): Team {
            $team = new Team(['name' => $name]);
            $team->owner()->associate($owner);
            $team->save();

            $team->memberships()->create(['user_id' => $owner->id, 'role' => TeamRole::Owner, 'receives_digests' => true]);

            return $team;
        });
    }

    public function rename(Team $team, string $name): Team
    {
        $team->update(['name' => $name]);

        return $team;
    }

    public function invite(Team $team, User $inviter, User $invitee): TeamInvitation
    {
        if (! $this->entitlements->teamIsActive($team)) {
            throw PaidFeatureRequired::teamWorkspace();
        }

        if ($team->hasMember($invitee)) {
            throw TeamActionFailed::alreadyMember();
        }

        $invitation = $team->invitations()->firstOrCreate(
            ['email' => mb_strtolower($invitee->email)],
            ['invited_by' => $inviter->id],
        );

        if ($invitation->wasRecentlyCreated) {
            $invitee->notify(new TeamInvitationNotification($team, $inviter));
        }

        return $invitation;
    }

    public function revoke(TeamInvitation $invitation): void
    {
        $invitation->delete();
    }

    public function accept(TeamInvitation $invitation, User $user): TeamMember
    {
        $this->assertAddressedTo($invitation, $user);

        return $this->db->transaction(function () use ($invitation, $user): TeamMember {
            $membership = TeamMember::query()->firstOrCreate(
                ['team_id' => $invitation->team_id, 'user_id' => $user->id],
                ['role' => TeamRole::Member, 'receives_digests' => true],
            );
            $invitation->delete();

            return $membership;
        });
    }

    public function decline(TeamInvitation $invitation, User $user): void
    {
        $this->assertAddressedTo($invitation, $user);
        $invitation->delete();
    }

    /**
     * Removes a member (by the owner) or lets a member leave. The owner cannot leave; deleting
     * the team is the way to close it.
     */
    public function removeMember(Team $team, User $member): void
    {
        if ($team->isOwnedBy($member)) {
            throw TeamActionFailed::ownerCannotLeave();
        }

        $team->memberships()->where('user_id', $member->id)->delete();
    }

    public function setReceivesDigests(TeamMember $membership, bool $receives): TeamMember
    {
        $membership->update(['receives_digests' => $receives]);

        return $membership;
    }

    /**
     * Deletes the team with its shared saved searches, digests, memberships and invitations.
     */
    public function delete(Team $team): void
    {
        $team->delete();
    }

    private function assertAddressedTo(TeamInvitation $invitation, User $user): void
    {
        if (mb_strtolower($invitation->email) !== mb_strtolower($user->email)) {
            throw TeamActionFailed::invitationNotFound();
        }
    }
}
