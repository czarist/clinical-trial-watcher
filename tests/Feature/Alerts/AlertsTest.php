<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Enums\AlertMatchReason;
use App\Models\Alert;
use App\Models\AlertMatch;
use App\Models\User;
use App\Notifications\AlertMatchesNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var list<array<string, mixed>> */
    private array $servedStudies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccessControl();
        $this->user = $this->userWithRole();
        Http::fake(['https://clinicaltrials.gov/api/v2/studies*' => fn () => Http::response($this->page($this->servedStudies))]);
    }

    public function test_a_user_creates_lists_updates_and_deletes_alerts(): void
    {
        $this->actingAsApiUser($this->user);

        $id = $this->postJson('/api/alerts', [
            'name' => 'Asthma in Brazil',
            'criteria' => ['condition' => 'asthma', 'country' => 'Brazil', 'status' => ['RECRUITING']],
            'frequency' => 'weekly',
        ])->assertCreated()
            ->assertJsonPath('data.criteria.status', ['RECRUITING'])
            ->assertJsonPath('data.notify_status_changes', true)
            ->json('data.id');

        $this->getJson('/api/alerts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.alerts_limit', 5);

        $this->patchJson("/api/alerts/{$id}", ['frequency' => 'instant', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.frequency', 'instant')
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.criteria.condition', 'asthma');

        $this->deleteJson("/api/alerts/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('alerts', ['id' => $id]);
    }

    public function test_alert_input_is_validated(): void
    {
        $this->actingAsApiUser($this->user)->postJson('/api/alerts', [
            'name' => 'Everything',
            'criteria' => ['status' => ['NOPE']],
            'frequency' => 'hourly',
        ])->assertUnprocessable()->assertJsonValidationErrors(['criteria.status.0', 'frequency']);

        $this->actingAsApiUser($this->user)->postJson('/api/alerts', [
            'name' => 'Everything',
            'criteria' => [],
            'frequency' => 'daily',
        ])->assertUnprocessable()->assertJsonValidationErrors('criteria');
    }

    public function test_the_plan_limits_the_number_of_alerts(): void
    {
        Alert::factory()->count(5)->for($this->user)->create();

        $this->actingAsApiUser($this->user)->postJson('/api/alerts', [
            'name' => 'One too many', 'criteria' => ['condition' => 'asthma'], 'frequency' => 'daily',
        ])->assertForbidden()->assertJsonPath('message', fn (string $message): bool => str_contains($message, '5 alert'));
    }

    public function test_the_signup_trial_uses_the_trial_plan_limit(): void
    {
        $user = $this->userWithRole(attributes: ['trial_ends_at' => now()->addDays(3)]);
        Alert::factory()->count(5)->for($user)->create();

        $this->actingAsApiUser($user)->postJson('/api/alerts', [
            'name' => 'Sixth', 'criteria' => ['condition' => 'asthma'], 'frequency' => 'daily',
        ])->assertCreated();

        $this->getJson('/api/auth/me')->assertJsonPath('billing.effective_plan.name', 'Pro');
    }

    public function test_users_cannot_access_alerts_of_other_users(): void
    {
        $other = Alert::factory()->for($this->userWithRole())->create();
        $this->actingAsApiUser($this->user);

        $this->getJson("/api/alerts/{$other->id}")->assertForbidden();
        $this->patchJson("/api/alerts/{$other->id}", ['name' => 'Mine now'])->assertForbidden();
        $this->deleteJson("/api/alerts/{$other->id}")->assertForbidden();
        $this->getJson("/api/alerts/{$other->id}/matches")->assertForbidden();
        $this->getJson('/api/alerts/999999')->assertNotFound();
    }

    public function test_unverified_users_cannot_use_alerts(): void
    {
        $user = $this->userWithRole(attributes: ['email_verified_at' => null]);

        $this->actingAsApiUser($user)->getJson('/api/alerts')->assertForbidden();
    }

    public function test_an_import_matches_instant_alerts_and_emails_the_owner(): void
    {
        Notification::fake();
        $alert = Alert::factory()->instant()->for($this->user)->create(['criteria' => ['condition' => 'asthma']]);
        $unrelated = Alert::factory()->instant()->for($this->user)->create(['criteria' => ['condition' => 'melanoma']]);
        $this->travel(1)->seconds();

        $this->import($this->studiesFixture()['studies']);

        $this->assertSame(2, $alert->matches()->count());
        $this->assertSame(0, $unrelated->matches()->count());
        $this->assertSame([AlertMatchReason::NewStudy], $alert->matches->pluck('reason')->unique()->values()->all());
        $this->assertSame(0, $alert->pendingMatches()->count());
        Notification::assertSentTo($this->user, AlertMatchesNotification::class, fn (AlertMatchesNotification $n): bool => $n->alert->is($alert) && $n->total === 2);
        Notification::assertSentTimes(AlertMatchesNotification::class, 1);
    }

    public function test_daily_alerts_wait_for_the_digest_and_report_status_changes(): void
    {
        Notification::fake();
        $study = $this->studiesFixture()['studies'][0];
        $this->import([$study]);

        $alert = Alert::factory()->for($this->user)->create(['criteria' => ['condition' => 'asthma']]);
        $this->travel(1)->seconds();
        $study['protocolSection']['statusModule']['overallStatus'] = 'ACTIVE_NOT_RECRUITING';
        $this->import([$study]);

        $match = AlertMatch::sole();
        $this->assertSame(AlertMatchReason::StatusChange, $match->reason);
        Notification::assertNothingSent();

        $this->artisan('alerts:send-digests', ['frequency' => 'daily'])->assertSuccessful();

        Notification::assertSentTo($this->user, AlertMatchesNotification::class);
        $this->assertNotNull($match->refresh()->notified_at);
        $this->artisan('alerts:send-digests', ['frequency' => 'instant'])->assertExitCode(2);
    }

    public function test_matches_of_an_alert_are_listed(): void
    {
        Notification::fake();
        $alert = Alert::factory()->for($this->user)->create(['criteria' => ['condition' => 'asthma']]);
        $this->travel(1)->seconds();
        $this->import([$this->studiesFixture()['studies'][0]]);

        $this->actingAsApiUser($this->user)->getJson("/api/alerts/{$alert->id}/matches")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reason', 'new_study')
            ->assertJsonStructure(['data' => [['trial' => ['nct_id', 'brief_title']]]]);
    }

    /**
     * @param  list<array<string, mixed>>  $studies
     */
    private function import(array $studies): void
    {
        $this->servedStudies = $studies;
        $this->artisan('clinicaltrials:fetch', ['--full' => true])->assertSuccessful();
    }
}
