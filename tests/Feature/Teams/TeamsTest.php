<?php

declare(strict_types=1);

namespace Tests\Feature\Teams;

use App\Models\Alert;
use App\Models\Plan;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class TeamsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->seedAccessControl();

        $this->owner = $this->userWithRole(attributes: ['email' => 'owner@example.com']);
        $this->subscribeTo($this->owner, Plan::TEAM_AI);
        $this->member = $this->userWithRole(attributes: ['email' => 'member@example.com']);
    }

    public function test_only_team_ai_subscribers_can_create_one_team(): void
    {
        $this->actingAsApiUser($this->member)->postJson('/api/teams', ['name' => 'No plan'])->assertStatus(402);

        $pro = $this->userWithRole();
        $this->subscribeTo($pro, Plan::PRO_AI);
        $this->actingAsApiUser($pro)->postJson('/api/teams', ['name' => 'Pro only'])->assertStatus(402);

        $this->actingAsApiUser($this->owner)->postJson('/api/teams', ['name' => 'Oncology'])
            ->assertCreated()
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.members.0.email', 'owner@example.com');

        $this->postJson('/api/teams', ['name' => 'Second'])->assertUnprocessable();
    }

    public function test_an_invitation_needs_a_registered_user_and_explicit_acceptance(): void
    {
        $team = $this->team();
        $this->actingAsApiUser($this->owner);

        $this->postJson("/api/teams/{$team->id}/invitations", ['email' => 'nobody@example.com'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson("/api/teams/{$team->id}/invitations", ['email' => 'member@example.com'])->assertCreated();
        Notification::assertSentTo($this->member, TeamInvitationNotification::class);

        // Invited is not a member yet.
        $this->actingAsApiUser($this->member)->getJson("/api/teams/{$team->id}")->assertForbidden();
        $this->getJson('/api/teams')->assertJsonCount(0, 'data');

        $invitation = TeamInvitation::sole();
        $this->actingAsApiUser($this->userWithRole())->postJson("/api/team-invitations/{$invitation->id}/accept")->assertNotFound();

        $this->actingAsApiUser($this->member)->getJson('/api/team-invitations')->assertJsonPath('data.0.team.name', 'Oncology');
        $this->postJson("/api/team-invitations/{$invitation->id}/accept")->assertOk()->assertJsonPath('data.is_owner', false);

        $this->getJson("/api/teams/{$team->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.members')
            // Members do not see each other's email addresses or the pending invitations.
            ->assertJsonPath('data.members.0.email', null)
            ->assertJsonMissingPath('data.invitations');
        $this->assertDatabaseCount('team_invitations', 0);
    }

    public function test_declining_removes_the_invitation(): void
    {
        $team = $this->team();
        $invitation = $team->invitations()->create(['email' => 'member@example.com', 'invited_by' => $this->owner->id]);

        $this->actingAsApiUser($this->member)->postJson("/api/team-invitations/{$invitation->id}/decline")->assertNoContent();

        $this->assertDatabaseCount('team_invitations', 0);
        $this->assertFalse($team->hasMember($this->member));
    }

    public function test_members_cannot_manage_the_team_and_the_owner_cannot_leave(): void
    {
        $team = $this->teamWithMember();
        $outsider = $this->userWithRole(attributes: ['email' => 'outsider@example.com']);

        $this->actingAsApiUser($this->member);
        $this->postJson("/api/teams/{$team->id}/invitations", ['email' => 'outsider@example.com'])->assertForbidden();
        $this->patchJson("/api/teams/{$team->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->deleteJson("/api/teams/{$team->id}")->assertForbidden();
        $this->deleteJson("/api/teams/{$team->id}/members/{$this->owner->id}")->assertForbidden();

        $this->actingAsApiUser($this->owner)->deleteJson("/api/teams/{$team->id}/members/{$this->owner->id}")->assertUnprocessable();
        $this->deleteJson("/api/teams/{$team->id}/members/{$this->member->id}")->assertNoContent();
        $this->assertFalse($team->hasMember($this->member));
        $this->assertFalse($team->hasMember($outsider));
    }

    public function test_a_member_can_leave_and_opt_out_of_team_digests(): void
    {
        $team = $this->teamWithMember();
        $this->actingAsApiUser($this->member);

        $this->patchJson("/api/teams/{$team->id}/membership", ['receives_digests' => false])
            ->assertOk()
            ->assertJsonPath('data.receives_digests', false);

        $this->deleteJson("/api/teams/{$team->id}/members/{$this->member->id}")->assertNoContent();
        $this->getJson("/api/teams/{$team->id}")->assertForbidden();
    }

    public function test_shared_saved_searches_are_managed_by_the_owner_and_visible_to_members_only(): void
    {
        $team = $this->teamWithMember();

        $id = $this->actingAsApiUser($this->owner)->postJson('/api/alerts', [
            'team_id' => $team->id,
            'name' => 'Shared oncology',
            'criteria' => ['condition' => 'lung cancer'],
            'frequency' => 'daily',
            'ai_digest_enabled' => true,
            'ai_digest_language' => 'pt_BR',
        ])->assertCreated()
            ->assertJsonPath('data.team_id', $team->id)
            ->assertJsonPath('data.ai_digest.enabled', true)
            ->json('data.id');

        // Shared searches are not mixed with the owner's personal ones.
        $this->getJson('/api/alerts')->assertJsonCount(0, 'data');

        $this->actingAsApiUser($this->member);
        $this->getJson("/api/alerts?team_id={$team->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/alerts/{$id}")->assertOk();
        $this->patchJson("/api/alerts/{$id}", ['name' => 'Mine now'])->assertForbidden();
        $this->deleteJson("/api/alerts/{$id}")->assertForbidden();
        $this->postJson('/api/alerts', [
            'team_id' => $team->id, 'name' => 'Member search', 'criteria' => ['condition' => 'x'], 'frequency' => 'daily',
        ])->assertForbidden();
    }

    public function test_teams_are_isolated_from_each_other(): void
    {
        $team = $this->teamWithMember();
        $shared = Alert::factory()->for($this->owner)->create(['team_id' => $team->id]);

        $otherOwner = $this->userWithRole();
        $this->subscribeTo($otherOwner, Plan::TEAM_AI);
        $otherTeam = $this->actingAsApiUser($otherOwner)->postJson('/api/teams', ['name' => 'Other'])->json('data.id');

        $this->getJson("/api/teams/{$team->id}")->assertForbidden();
        $this->getJson("/api/alerts?team_id={$team->id}")->assertForbidden();
        $this->getJson("/api/alerts/{$shared->id}")->assertForbidden();
        $this->getJson("/api/digests?team_id={$team->id}")->assertForbidden();
        $this->postJson('/api/alerts', [
            'team_id' => $team->id, 'name' => 'Intrusion', 'criteria' => ['condition' => 'x'], 'frequency' => 'daily',
        ])->assertForbidden();
        $this->postJson("/api/teams/{$team->id}/invitations", ['email' => 'member@example.com'])->assertForbidden();

        $this->actingAsApiUser($this->member)->getJson("/api/teams/{$otherTeam}")->assertForbidden();
    }

    public function test_the_workspace_closes_when_the_owners_team_ai_subscription_ends(): void
    {
        $team = $this->teamWithMember();
        $this->owner->subscriptions()->update(['stripe_status' => 'canceled', 'ends_at' => now()->subMinute()]);

        $this->actingAsApiUser($this->member)->getJson("/api/teams/{$team->id}")->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson("/api/alerts?team_id={$team->id}")->assertStatus(402);
        $this->getJson("/api/digests?team_id={$team->id}")->assertStatus(402);
    }

    private function team(): Team
    {
        $id = $this->actingAsApiUser($this->owner)->postJson('/api/teams', ['name' => 'Oncology'])->assertCreated()->json('data.id');

        return Team::findOrFail($id);
    }

    private function teamWithMember(): Team
    {
        $team = $this->team();
        $invitation = $team->invitations()->create(['email' => $this->member->email, 'invited_by' => $this->owner->id]);
        $this->actingAsApiUser($this->member)->postJson("/api/team-invitations/{$invitation->id}/accept")->assertOk();

        return $team;
    }
}
