<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccessControl();
    }

    public function test_login_returns_a_bearer_token(): void
    {
        $user = $this->userWithRole();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in'])
            ->assertJsonPath('token_type', 'bearer')
            ->assertJsonMissingPath('status_code');
    }

    public function test_invalid_credentials_return_http_401(): void
    {
        $user = $this->userWithRole();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Invalid email or password.')
            ->assertJsonMissingPath('access_token');
    }

    public function test_login_input_is_validated(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_is_rate_limited(): void
    {
        $user = $this->userWithRole();

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->assertTooManyRequests();
    }

    public function test_protected_routes_reject_missing_and_invalid_tokens(): void
    {
        $this->getJson('/api/auth/me')->assertUnauthorized();
        $this->get('/api/auth/me')->assertUnauthorized();
        $this->withToken('not-a-jwt')->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_me_returns_the_user_with_permissions_and_billing_summary(): void
    {
        $user = $this->userWithRole();

        $this->actingAsApiUser($user)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.roles.0.name', RoleName::User->value)
            ->assertJsonPath('data.permissions', ['view_clinical_trials', 'create_alerts'])
            ->assertJsonPath('billing.effective_plan.name', 'Basic')
            ->assertJsonPath('billing.alerts_limit', 5)
            ->assertJsonMissingPath('data.password');
    }

    public function test_logout_invalidates_the_token(): void
    {
        $user = $this->userWithRole();
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->json('access_token');

        $this->withToken($token)->postJson('/api/auth/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_refresh_returns_a_new_token(): void
    {
        $user = $this->userWithRole();
        $token = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])->json('access_token');

        $newToken = $this->withToken($token)->postJson('/api/auth/refresh')
            ->assertOk()
            ->json('access_token');

        $this->assertIsString($newToken);
        $this->assertNotSame($token, $newToken);
    }

    public function test_registration_creates_a_regular_account_with_trial_and_sends_verification(): void
    {
        Notification::fake();

        $response = $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ]);

        $response->assertCreated()
            ->assertJsonStructure(['access_token', 'user' => ['id', 'email']])
            ->assertJsonPath('user.email_verified', false)
            ->assertJsonPath('user.roles.0.name', RoleName::User->value);

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertSame('Basic', $user->plan->name);
        $this->assertTrue($user->onGenericTrial());
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_registration_validates_input(): void
    {
        $existing = $this->userWithRole();

        $this->postJson('/api/auth/register', [
            'name' => '',
            'email' => $existing->email,
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_the_profile_and_password_can_be_updated(): void
    {
        Notification::fake();
        $user = $this->userWithRole();

        $this->actingAsApiUser($user)->putJson('/api/account/profile', ['name' => 'Renamed', 'email' => 'renamed@example.com'])
            ->assertOk()
            ->assertJsonPath('data.email_verified', false);
        Notification::assertSentTo($user->refresh(), VerifyEmail::class);

        $this->actingAsApiUser($user)->putJson('/api/account/password', [
            'current_password' => 'wrong',
            'password' => 'another-long-password',
            'password_confirmation' => 'another-long-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAsApiUser($user)->putJson('/api/account/password', [
            'current_password' => 'password',
            'password' => 'another-long-password',
            'password_confirmation' => 'another-long-password',
        ])->assertNoContent();

        $this->postJson('/api/auth/login', ['email' => 'renamed@example.com', 'password' => 'another-long-password'])->assertOk();
    }
}
