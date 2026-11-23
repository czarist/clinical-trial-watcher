<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\SubscriptionGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\FakeSubscriptionGateway;
use Tests\TestCase;

final class BillingTest extends TestCase
{
    use RefreshDatabase;

    private FakeSubscriptionGateway $gateway;

    private User $user;

    private Plan $pro;

    private Plan $enterprise;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccessControl();

        $this->gateway = new FakeSubscriptionGateway;
        $this->app->instance(SubscriptionGateway::class, $this->gateway);

        $this->pro = Plan::where('name', 'Pro')->firstOrFail();
        $this->pro->update(['stripe_price_id' => 'price_pro']);
        $this->enterprise = Plan::where('name', 'Enterprise')->firstOrFail();
        $this->enterprise->update(['stripe_price_id' => 'price_enterprise']);
        $this->user = $this->userWithRole(attributes: ['stripe_id' => 'cus_test']);
    }

    public function test_plans_are_public_and_hide_stripe_ids(): void
    {
        $this->getJson('/api/plans')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.name', 'Basic')
            ->assertJsonPath('data.0.is_free', true)
            ->assertJsonPath('data.1.alerts_limit', 50)
            ->assertJsonMissingPath('data.1.stripe_price_id');
    }

    public function test_a_first_paid_plan_starts_a_checkout_with_the_plan_trial(): void
    {
        $this->actingAsApiUser($this->user)->postJson('/api/billing/plan', ['plan_id' => $this->pro->id])
            ->assertOk()
            ->assertJsonPath('checkout_url', 'https://checkout.stripe.test/session/'.$this->pro->id);

        $this->assertSame([['checkout', $this->pro->id, 14]], $this->gateway->calls);
    }

    public function test_no_plan_trial_is_offered_during_the_signup_trial(): void
    {
        $this->user->update(['trial_ends_at' => now()->addDays(5)]);

        $this->actingAsApiUser($this->user)->postJson('/api/billing/plan', ['plan_id' => $this->pro->id])->assertOk();

        $this->assertSame([['checkout', $this->pro->id, 0]], $this->gateway->calls);
    }

    public function test_a_plan_without_a_stripe_price_cannot_be_bought(): void
    {
        $this->pro->update(['stripe_price_id' => null]);

        $this->actingAsApiUser($this->user)->postJson('/api/billing/plan', ['plan_id' => $this->pro->id])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This plan cannot be purchased online.');
    }

    public function test_subscribers_swap_plans_and_return_to_free_by_cancelling(): void
    {
        $this->subscribe($this->pro);

        $this->actingAsApiUser($this->user)->postJson('/api/billing/plan', ['plan_id' => $this->enterprise->id])
            ->assertOk()
            ->assertJsonPath('checkout_url', null)
            ->assertJsonPath('data.effective_plan.name', 'Enterprise');
        $this->assertSame('Enterprise', $this->user->refresh()->plan->name);

        $basic = Plan::where('name', 'Basic')->firstOrFail();
        $this->postJson('/api/billing/plan', ['plan_id' => $basic->id])
            ->assertOk()
            ->assertJsonPath('data.subscription.on_grace_period', true);

        $this->postJson('/api/billing/resume')->assertOk()->assertJsonPath('data.subscription.on_grace_period', false);
        $this->assertSame(['swap', 'cancel', 'resume'], array_column($this->gateway->calls, 0));
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

        $this->actingAsApiUser($user)->postJson('/api/billing/plan', ['plan_id' => $this->pro->id])->assertForbidden();
    }

    public function test_stripe_subscription_webhooks_update_the_users_plan(): void
    {
        $payload = fn (string $status, ?int $endedAt = null): array => [
            'id' => 'evt_'.$status,
            'type' => $status === 'deleted' ? 'customer.subscription.deleted' : 'customer.subscription.created',
            'data' => ['object' => [
                'id' => 'sub_123',
                'object' => 'subscription',
                'customer' => 'cus_test',
                'status' => $status === 'deleted' ? 'canceled' : 'active',
                'quantity' => 1,
                'cancel_at_period_end' => false,
                'trial_end' => null,
                'ended_at' => $endedAt,
                'current_period_end' => now()->addMonth()->timestamp,
                'metadata' => [],
                'items' => ['data' => [[
                    'id' => 'si_123',
                    'price' => ['id' => 'price_pro', 'product' => 'prod_pro'],
                    'quantity' => 1,
                ]]],
            ]],
        ];

        $this->postJson('/stripe/webhook', $payload('created'))->assertOk();

        $this->assertSame('Pro', $this->user->refresh()->plan->name);
        $this->assertSame($this->pro->id, Subscription::sole()->plan_id);

        $this->postJson('/stripe/webhook', $payload('deleted', now()->timestamp))->assertOk();

        $this->assertSame('Basic', $this->user->refresh()->plan->name);
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
