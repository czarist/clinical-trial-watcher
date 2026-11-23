<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\StripePrice;
use App\Services\Billing\SubscriptionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSubscriptionGateway;
use Tests\TestCase;

final class BillingTest extends TestCase
{
    use RefreshDatabase;

    private FakeSubscriptionGateway $gateway;

    private User $user;

    private Plan $proAi;

    private Plan $teamAi;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'billing.plans.Pro AI.stripe_price' => 'price_pro_ai',
            'billing.plans.Team AI.stripe_price' => 'price_team_ai',
        ]);
        $this->seedAccessControl();

        $this->gateway = new FakeSubscriptionGateway;
        $this->app->instance(SubscriptionGateway::class, $this->gateway);

        $this->proAi = Plan::where('name', Plan::PRO_AI)->firstOrFail();
        $this->teamAi = Plan::where('name', Plan::TEAM_AI)->firstOrFail();
        $this->user = $this->userWithRole(attributes: ['stripe_id' => 'cus_test']);
    }

    public function test_plans_are_free_pro_ai_and_team_ai_in_usd_without_legacy_plans(): void
    {
        Plan::factory()->legacy('Pro', 29.99, 'price_legacy_pro')->create();

        $this->getJson('/api/plans')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Free')
            ->assertJsonPath('data.0.price_label', '$0')
            ->assertJsonPath('data.0.ai_digests', false)
            ->assertJsonPath('data.1.name', 'Pro AI')
            ->assertJsonPath('data.1.currency', 'USD')
            ->assertJsonPath('data.1.price_label', '$19/month')
            ->assertJsonPath('data.1.ai_digests', true)
            ->assertJsonPath('data.1.is_purchasable', true)
            ->assertJsonPath('data.2.name', 'Team AI')
            ->assertJsonPath('data.2.price_label', '$99/month per team')
            ->assertJsonPath('data.2.team_workspace', true)
            ->assertJsonMissingPath('data.1.stripe_price_id');
    }

    public function test_new_accounts_start_on_free_without_a_trial(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'a-long-password-123',
            'password_confirmation' => 'a-long-password-123',
        ])->assertCreated();

        $user = User::where('email', 'new@example.com')->sole();
        $this->assertNull($user->trial_ends_at);
        $this->assertSame(Plan::FREE, $user->plan->name);
    }

    public function test_a_paid_plan_starts_a_checkout_without_a_trial(): void
    {
        $this->actingAsApiUser($this->user)->postJson('/api/billing/plan', ['plan_id' => $this->proAi->id])
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.test/session/'.$this->proAi->id);

        $this->assertSame([['checkout', $this->proAi->id, 0]], $this->gateway->calls);
    }

    public function test_a_misconfigured_stripe_price_blocks_checkout(): void
    {
        $this->gateway->prices['price_pro_ai'] = new StripePrice('price_pro_ai', 1900, 'brl', 'month', 1, true);

        $this->actingAsApiUser($this->user)->postJson('/api/billing/plan', ['plan_id' => $this->proAi->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This plan is temporarily unavailable for purchase. Please try again later.');

        $this->gateway->prices['price_pro_ai'] = new StripePrice('price_pro_ai', 1900, 'usd', 'year', 1, true);
        $this->postJson('/api/billing/plan', ['plan_id' => $this->proAi->id])->assertUnprocessable();

        $this->assertSame([], $this->gateway->calls);
    }

    public function test_check_prices_links_the_configured_prices_and_reports_mismatches(): void
    {
        $this->proAi->update(['stripe_price_id' => null]);

        $this->artisan('billing:check-prices')->assertSuccessful();
        $this->assertSame('price_pro_ai', $this->proAi->refresh()->stripe_price_id);

        $this->gateway->prices['price_team_ai'] = new StripePrice('price_team_ai', 9900, 'usd', 'month', 3, true);
        $this->artisan('billing:check-prices')
            ->expectsOutputToContain('Team AI: the interval is 3 month, expected 1 month')
            ->assertFailed();
    }

    public function test_legacy_plans_cannot_be_bought(): void
    {
        $legacy = Plan::factory()->legacy('Enterprise', 99.99, 'price_enterprise')->create();

        $this->actingAsApiUser($this->user)->postJson('/api/billing/plan', ['plan_id' => $legacy->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This plan cannot be purchased online.');
    }

    public function test_legacy_subscribers_keep_their_plan_without_ai_and_can_manage_or_move_to_pro_ai(): void
    {
        $legacy = Plan::factory()->legacy('Pro', 29.99, 'price_legacy_pro')->create();
        $this->subscribe($legacy);
        $this->actingAsApiUser($this->user);

        $this->getJson('/api/billing')
            ->assertOk()
            ->assertJsonPath('data.effective_plan.name', 'Pro')
            ->assertJsonPath('data.effective_plan.is_legacy', true)
            ->assertJsonPath('data.features.ai_digests', false)
            ->assertJsonPath('data.subscription.status', 'active');

        $this->getJson('/api/billing/portal')->assertOk();

        $this->postJson('/api/billing/plan', ['plan_id' => $this->proAi->id])
            ->assertOk()
            ->assertJsonPath('checkout_url', null)
            ->assertJsonPath('data.effective_plan.name', 'Pro AI')
            ->assertJsonPath('data.features.ai_digests', true);

        $this->assertSame(['portal', 'swap'], array_column($this->gateway->calls, 0));
    }

    public function test_subscribers_swap_plans_and_return_to_free_by_cancelling(): void
    {
        $this->subscribe($this->proAi);
        $this->actingAsApiUser($this->user);

        $this->postJson('/api/billing/plan', ['plan_id' => $this->teamAi->id])
            ->assertOk()
            ->assertJsonPath('data.effective_plan.name', 'Team AI')
            ->assertJsonPath('data.features.team_workspace', true);

        $free = Plan::where('name', Plan::FREE)->firstOrFail();
        $this->postJson('/api/billing/plan', ['plan_id' => $free->id])
            ->assertOk()
            ->assertJsonPath('data.subscription.on_grace_period', true)
            // Paid access lasts until the end of the paid period.
            ->assertJsonPath('data.features.ai_digests', true);

        $this->postJson('/api/billing/resume')->assertOk()->assertJsonPath('data.subscription.on_grace_period', false);
        $this->assertSame(['swap', 'cancel', 'resume'], array_column($this->gateway->calls, 0));
    }

    public function test_paid_features_end_when_the_subscription_ends(): void
    {
        $this->subscribe($this->proAi);
        $this->user->subscriptions()->update(['stripe_status' => 'canceled', 'ends_at' => now()->subMinute()]);

        $this->actingAsApiUser($this->user)->getJson('/api/billing')
            ->assertJsonPath('data.effective_plan.name', 'Free')
            ->assertJsonPath('data.features.ai_digests', false);
    }

    public function test_cancel_and_portal_require_a_subscription(): void
    {
        $user = $this->userWithRole();
        $this->actingAsApiUser($user);

        $this->postJson('/api/billing/cancel')->assertUnprocessable();
        $this->getJson('/api/billing/portal')->assertUnprocessable();
        $this->postJson('/api/billing/resume')->assertUnprocessable();
    }

    public function test_billing_requires_a_verified_email(): void
    {
        $user = $this->userWithRole(attributes: ['email_verified_at' => null]);

        $this->actingAsApiUser($user)->postJson('/api/billing/plan', ['plan_id' => $this->proAi->id])->assertForbidden();
    }

    public function test_stripe_subscription_webhooks_update_the_users_plan_including_legacy_prices(): void
    {
        $legacy = Plan::factory()->legacy('Pro', 29.99, 'price_legacy_pro')->create();
        $payload = fn (string $event, string $price, ?int $endedAt = null): array => [
            'id' => 'evt_'.$event.$price,
            'type' => 'customer.subscription.'.$event,
            'data' => ['object' => [
                'id' => 'sub_123',
                'object' => 'subscription',
                'customer' => 'cus_test',
                'status' => $event === 'deleted' ? 'canceled' : 'active',
                'quantity' => 1,
                'cancel_at_period_end' => false,
                'trial_end' => null,
                'ended_at' => $endedAt,
                'current_period_end' => now()->addMonth()->timestamp,
                'metadata' => [],
                'items' => ['data' => [[
                    'id' => 'si_123',
                    'price' => ['id' => $price, 'product' => 'prod_x'],
                    'quantity' => 1,
                ]]],
            ]],
        ];

        $this->postJson('/stripe/webhook', $payload('created', 'price_pro_ai'))->assertOk();
        $this->assertSame(Plan::PRO_AI, $this->user->refresh()->plan->name);
        $this->assertSame($this->proAi->id, Subscription::sole()->plan_id);

        // A legacy price keeps mapping to its legacy plan.
        $this->postJson('/stripe/webhook', $payload('updated', 'price_legacy_pro'))->assertOk();
        $this->assertSame($legacy->id, $this->user->refresh()->plan_id);

        $this->postJson('/stripe/webhook', $payload('deleted', 'price_legacy_pro', now()->timestamp))->assertOk();
        $this->assertSame(Plan::FREE, $this->user->refresh()->plan->name);
    }

    private function subscribe(Plan $plan): void
    {
        $this->user->subscriptions()->create([
            'type' => 'default',
            'plan_id' => $plan->id,
            'stripe_id' => 'sub_existing',
            'stripe_status' => 'active',
            'stripe_price' => $plan->stripe_price_id,
            'quantity' => 1,
        ]);
    }
}
