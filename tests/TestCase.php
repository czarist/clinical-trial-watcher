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
            'plan_id' => Plan::where('name', Plan::BASIC)->value('id'),
            ...$attributes,
        ]);
        $user->roles()->attach(Role::where('name', $role->value)->firstOrFail());

        return $user;
    }

    protected function actingAsApiUser(User $user): static
    {
        $this->app['auth']->forgetGuards();

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
