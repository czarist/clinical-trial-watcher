<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

final class PasswordResetAndVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccessControl();
        config(['app.frontend_url' => 'https://app.test']);
    }

    public function test_a_reset_link_is_sent_without_revealing_whether_the_account_exists(): void
    {
        Notification::fake();
        $user = $this->userWithRole();

        $known = $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk()->json('message');
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@example.com'])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;

            return str_starts_with($url, 'https://app.test/reset-password?token=');
        });
    }

    public function test_the_password_can_be_reset_with_a_valid_token(): void
    {
        $user = $this->userWithRole();
        $token = Password::createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertOk();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'brand-new-password'])->assertOk();
    }

    public function test_an_invalid_reset_token_is_rejected(): void
    {
        $user = $this->userWithRole();

        $this->postJson('/api/auth/reset-password', [
            'token' => 'invalid',
            'email' => $user->email,
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_the_signed_verification_link_verifies_the_email_and_redirects_to_the_web_app(): void
    {
        Notification::fake();
        $user = $this->userWithRole(attributes: ['email_verified_at' => null]);
        $user->sendEmailVerificationNotification();

        $url = null;
        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user, &$url): bool {
            $url = $notification->toMail($user)->actionUrl;

            return true;
        });

        $this->get($url)->assertRedirect('https://app.test/login?verified=1');
        $this->assertTrue($user->refresh()->hasVerifiedEmail());
    }

    public function test_a_tampered_verification_link_is_rejected(): void
    {
        $user = $this->userWithRole(attributes: ['email_verified_at' => null]);

        $this->get("/api/auth/verify-email/{$user->id}/".sha1($user->email))->assertForbidden();
        $this->assertFalse($user->refresh()->hasVerifiedEmail());
    }

    public function test_the_verification_email_can_be_sent_again(): void
    {
        Notification::fake();
        $user = $this->userWithRole(attributes: ['email_verified_at' => null]);

        $this->actingAsApiUser($user)->postJson('/api/auth/email/verification-notification')->assertAccepted();

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
