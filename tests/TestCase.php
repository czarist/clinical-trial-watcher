<?php

declare(strict_types=1);

namespace Tests;

use App\Enums\RoleName;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\InitialSaasSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Tymon\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function seedAccessControl(): void
    {
        $this->seed(InitialSaasSeeder::class);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function userWithRole(RoleName $role = RoleName::User, array $attributes = []): User
    {
        $user = User::factory()->create([
            'plan_id' => Plan::where('name', Plan::FREE)->value('id'),
            ...$attributes,
        ]);
        $user->roles()->attach(Role::where('name', $role->value)->firstOrFail());

        return $user;
    }

    /**
     * Gives the user an active local subscription to the named plan (no Stripe call).
     */
    protected function subscribeTo(User $user, string $planName): void
    {
        $plan = Plan::where('name', $planName)->firstOrFail();
        $plan->stripe_price_id ??= 'price_'.str_replace(' ', '_', strtolower($planName));
        $plan->save();

        $user->subscriptions()->create([
            'type' => 'default',
            'plan_id' => $plan->id,
            'stripe_id' => 'sub_'.$user->id,
            'stripe_status' => 'active',
            'stripe_price' => $plan->stripe_price_id,
            'quantity' => 1,
        ]);
    }

    protected function actingAsApiUser(User $user): static
    {
        $this->app['auth']->forgetGuards();
        // The JWT services cache the token they parsed from the previous request; the guard
        // uses tymon.jwt and the facade tymon.jwt.auth.
        $this->app['tymon.jwt']->unsetToken();
        JWTAuth::unsetToken();

        return $this->withToken(JWTAuth::fromUser($user));
    }

    /**
     * Recorded ClinicalTrials.gov API v2 response (two studies, results and document sections removed).
     *
     * @return array{studies: list<array<string, mixed>>, nextPageToken: string}
     */
    protected function studiesFixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/Fixtures/clinicaltrials/studies-page-1.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array<string, mixed>>  $studies
     * @return array<string, mixed>
     */
    protected function page(array $studies, ?string $nextPageToken = null): array
    {
        return array_filter(['studies' => $studies, 'nextPageToken' => $nextPageToken], static fn (mixed $v): bool => $v !== null);
    }
}
