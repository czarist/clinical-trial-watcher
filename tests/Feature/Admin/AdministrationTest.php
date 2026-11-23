<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\ImportRunStatus;
use App\Enums\RoleName;
use App\Jobs\ImportClinicalTrialsJob;
use App\Models\ImportRun;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\InitialSaasSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class AdministrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAccessControl();
        $this->admin = $this->userWithRole(RoleName::Admin);
    }

    public function test_regular_users_cannot_use_admin_routes(): void
    {
        $this->actingAsApiUser($this->userWithRole());

        foreach (['/api/admin/users', '/api/admin/roles', '/api/admin/permissions', '/api/admin/imports'] as $uri) {
            $this->getJson($uri)->assertForbidden();
        }

        $this->postJson('/api/admin/plans', [])->assertForbidden();
        $this->postJson('/api/admin/imports')->assertForbidden();
    }

    public function test_admins_list_users_and_change_their_roles_and_plan(): void
    {
        $user = $this->userWithRole(attributes: ['name' => 'Searchable Person']);
        $admin = Role::where('name', RoleName::Admin->value)->firstOrFail();
        $enterprise = Plan::where('name', Plan::TEAM_AI)->firstOrFail();
        $this->actingAsApiUser($this->admin);

        $this->getJson('/api/admin/users?search=searchable')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $user->id);

        $this->patchJson("/api/admin/users/{$user->id}", ['role_ids' => [$admin->id], 'plan_id' => $enterprise->id])
            ->assertOk()
            ->assertJsonPath('data.roles.0.name', 'Admin')
            ->assertJsonPath('data.plan.name', 'Team AI');

        $this->assertTrue($user->refresh()->hasPermission('manage_users'));
    }

    public function test_admins_manage_plans(): void
    {
        $this->actingAsApiUser($this->admin);

        $id = $this->postJson('/api/admin/plans', [
            'name' => 'Team', 'price' => 49.5, 'billing_cycle' => 'monthly', 'stripe_price_id' => 'price_team',
        ])->assertCreated()->assertJsonPath('data.price', '49.50')->assertJsonPath('data.stripe_price_id', 'price_team')->json('data.id');

        $this->getJson('/api/admin/plans')->assertOk()->assertJsonFragment(['name' => 'Team']);
        $this->patchJson("/api/admin/plans/{$id}", ['price' => 59])->assertOk()->assertJsonPath('data.price', '59.00');
        $this->postJson('/api/admin/plans', ['name' => 'Team', 'price' => 1, 'billing_cycle' => 'monthly'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->deleteJson("/api/admin/plans/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('plans', ['id' => $id]);
    }

    public function test_admins_change_role_permissions(): void
    {
        $role = Role::where('name', RoleName::User->value)->firstOrFail();
        $view = Permission::where('name', 'view_clinical_trials')->firstOrFail();
        $this->actingAsApiUser($this->admin);

        $this->getJson('/api/admin/roles')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/permissions')->assertOk()->assertJsonCount(6, 'data');
        $this->putJson("/api/admin/roles/{$role->id}/permissions", ['permission_ids' => [$view->id]])
            ->assertOk()
            ->assertJsonPath('data.permissions.0.name', 'view_clinical_trials')
            ->assertJsonCount(1, 'data.permissions');
    }

    public function test_admins_see_import_runs_and_queue_an_import(): void
    {
        Queue::fake();
        ImportRun::create(['status' => ImportRunStatus::Succeeded, 'started_at' => now(), 'pages' => 3, 'studies_created' => 10]);
        $this->actingAsApiUser($this->admin);

        $this->getJson('/api/admin/imports')->assertOk()->assertJsonPath('data.0.created', 10);
        $this->postJson('/api/admin/imports', ['since' => '2026-11-01', 'max_pages' => 2])->assertAccepted();
        $this->postJson('/api/admin/imports', ['since' => '2999-01-01'])->assertUnprocessable();

        Queue::assertPushed(ImportClinicalTrialsJob::class, fn (ImportClinicalTrialsJob $job): bool => $job->options->maxPages === 2);
    }

    public function test_seeders_can_run_twice_without_duplicates(): void
    {
        $this->seed(InitialSaasSeeder::class);

        $this->assertSame(3, Plan::count());
        $this->assertSame(2, Role::count());
        $this->assertSame(6, Permission::count());
        $this->assertSame(['ai_digests' => true, 'team_workspace' => false], Plan::where('name', Plan::PRO_AI)->firstOrFail()->features);
        $this->assertSame(['Free', 'Pro AI', 'Team AI'], Plan::orderBy('price')->pluck('name')->all());
    }
}
