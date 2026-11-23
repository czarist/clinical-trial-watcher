<?php

declare(strict_types=1);

namespace Tests\Feature\I18n;

use App\Enums\Locale;
use App\Models\ClinicalTrials\ClinicalTrialChange;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TeamInvitationNotification;
use App\Services\Digests\FactualChangeWriter;
use App\Services\Digests\SummaryValidator;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccessControl();
    }

    public function test_api_errors_follow_the_accept_language_header(): void
    {
        $user = $this->userWithRole();

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong'], ['Accept-Language' => 'pt-BR,pt;q=0.9'])
            ->assertUnauthorized()
            ->assertHeader('Content-Language', 'pt-BR')
            ->assertJsonPath('message', trans('auth.failed', [], 'pt_BR'));

        $this->assertNotSame(trans('auth.failed', [], 'en'), trans('auth.failed', [], 'pt_BR'));
    }

    public function test_requests_without_a_supported_language_are_answered_in_english(): void
    {
        $this->getJson('/api/auth/me', ['Accept-Language' => 'fr-FR'])
            ->assertUnauthorized()
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', 'Please log in to continue.');
    }

    public function test_validation_messages_and_field_names_are_translated(): void
    {
        $response = $this->postJson('/api/auth/register', [], ['Accept-Language' => 'ja'])->assertUnprocessable();

        $message = $response->json('errors.email.0');
        $this->assertSame(trans('validation.required', ['attribute' => trans('validation.attributes.email', [], 'ja')], 'ja'), $message);
        $this->assertStringNotContainsString('field is required', $message);
    }

    public function test_business_errors_are_translated(): void
    {
        $user = $this->userWithRole();
        config(['billing.saved_search_limit' => 0]);

        $this->actingAsApiUser($user)
            ->postJson('/api/alerts', ['name' => 'Asma', 'criteria' => ['condition' => 'asthma'], 'frequency' => 'daily'], ['Accept-Language' => 'es'])
            ->assertForbidden()
            ->assertJsonPath('message', trans('errors.alert_limit', ['limit' => 0], 'es'));
    }

    public function test_registration_stores_the_chosen_language_or_the_request_language(): void
    {
        Notification::fake();
        $password = ['password' => 'secret-password-1', 'password_confirmation' => 'secret-password-1'];

        $this->postJson('/api/auth/register', ['name' => 'Kim', 'email' => 'kim@example.com', ...$password], ['Accept-Language' => 'ko-KR'])
            ->assertCreated()
            ->assertJsonPath('user.locale', 'ko');

        $this->postJson('/api/auth/register', ['name' => 'Lin', 'email' => 'lin@example.com', 'locale' => 'zh_TW', ...$password])
            ->assertCreated()
            ->assertJsonPath('user.locale', 'zh_TW');

        $kim = User::where('email', 'kim@example.com')->sole();
        Notification::assertSentTo($kim, VerifyEmail::class, fn (VerifyEmail $notification, array $channels, User $notifiable, ?string $locale): bool => $locale === 'ko');
    }

    public function test_the_account_language_can_be_changed_and_is_validated(): void
    {
        $user = $this->userWithRole();

        $this->actingAsApiUser($user)->putJson('/api/account/locale', ['locale' => 'ja'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'ja');
        $this->assertSame(Locale::Japanese, $user->fresh()->locale);

        $this->putJson('/api/account/locale', ['locale' => 'fr'])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->assertSame(Locale::English, $this->userWithRole()->locale);
    }

    public function test_emails_use_the_recipient_language(): void
    {
        Notification::fake();
        $owner = $this->userWithRole();
        $invitee = $this->userWithRole(attributes: ['locale' => Locale::SimplifiedChinese]);
        $team = Team::forceCreate(['name' => 'Equipe', 'owner_id' => $owner->id]);

        $invitee->notify(new TeamInvitationNotification($team, $owner));

        Notification::assertSentTo(
            $invitee,
            TeamInvitationNotification::class,
            function (TeamInvitationNotification $notification, array $channels, User $notifiable, ?string $locale) use ($team): bool {
                app()->setLocale((string) $locale);

                return $locale === 'zh_CN'
                    && $notification->toMail($notifiable)->subject === trans('mail.team_invitation.subject', ['team' => $team->name], 'zh_CN');
            },
        );
    }

    public function test_factual_digest_text_and_dates_are_written_in_every_language(): void
    {
        $writer = app(FactualChangeWriter::class);
        $change = new ClinicalTrialChange([
            'type' => 'status_changed',
            'details' => [['field' => 'date', 'name' => 'primary_completion_date', 'before' => ['date' => '2027-05'], 'after' => ['date' => '2028-03', 'type' => 'ESTIMATED']]],
        ]);

        $this->assertSame('2028年3月', mb_substr($writer->date(['date' => '2028-03'], Locale::Japanese), 0, 7));
        $this->assertStringNotContainsString('日', $writer->date(['date' => '2028-03'], Locale::Japanese));

        foreach (Locale::cases() as $locale) {
            $facts = $writer->facts($change, $locale);
            $this->assertCount(1, $facts);
            $this->assertStringNotContainsString('digest.', $facts[0], "{$locale->value} has every digest key");
        }
    }

    public function test_advice_and_efficacy_wording_is_rejected_in_every_language(): void
    {
        $validator = new SummaryValidator(600);
        $nct = 'NCT01234567';

        $this->assertTrue($validator->isAcceptable('登记信息显示该研究已开始招募。', $nct));
        $this->assertTrue($validator->isAcceptable('El registro cambió a Reclutando.', $nct));

        foreach (['我们推荐您参加这项研究。', '這項治療已證實有效。', 'この治療は効果的です。', '이 치료는 효과적입니다.', 'Usted debe inscribirse.', 'Es un tratamiento con eficacia comprobada.'] as $summary) {
            $this->assertFalse($validator->isAcceptable($summary, $nct), $summary);
        }
    }
}
