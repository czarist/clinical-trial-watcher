<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PermissionName;
use App\Enums\RoleName;
use App\Models\Permission;
use App\Models\Plan;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Plans, roles and permissions. Safe to run more than once.
 */
final class InitialSaasSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['name' => 'Basic', 'price' => 0.00, 'billing_cycle' => 'monthly', 'trial_days' => 0, 'features' => ['alerts_limit' => 5]],
            ['name' => 'Pro', 'price' => 29.99, 'billing_cycle' => 'monthly', 'trial_days' => 14, 'features' => ['alerts_limit' => 50]],
            ['name' => 'Enterprise', 'price' => 99.99, 'billing_cycle' => 'monthly', 'trial_days' => 14, 'features' => ['alerts_limit' => 9999]],
        ];

        // stripe_price_id is set by an administrator (PATCH /api/admin/plans/{plan}) and is kept here.
        foreach ($plans as $plan) {
            Plan::updateOrCreate(['name' => $plan['name']], $plan);
        }

        foreach (RoleName::cases() as $role) {
            Role::updateOrCreate(['name' => $role->value], ['description' => $role === RoleName::Admin ? 'Administrator role' : 'Regular user']);
        }

        foreach (PermissionName::cases() as $permission) {
            Permission::updateOrCreate(['name' => $permission->value], ['description' => $permission->description()]);
        }

        Role::where('name', RoleName::Admin->value)->firstOrFail()
            ->permissions()->sync(Permission::pluck('id'));

        Role::where('name', RoleName::User->value)->firstOrFail()
            ->permissions()->sync(
                Permission::whereIn('name', array_map(fn (PermissionName $p): string => $p->value, PermissionName::forRegularUsers()))->pluck('id'),
            );

        $this->command?->info('Plans, roles and permissions seeded.');
    }
}
