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
        // Current plans, in USD per month. Legacy plans (Basic, Pro, Enterprise) are not
        // created on new installs; where they exist they stay as they are, marked legacy.
        $plans = [
            ['name' => Plan::FREE, 'price' => 0.00, 'features' => ['ai_digests' => false, 'team_workspace' => false]],
            ['name' => Plan::PRO_AI, 'price' => 19.00, 'features' => ['ai_digests' => true, 'team_workspace' => false]],
            ['name' => Plan::TEAM_AI, 'price' => 99.00, 'features' => ['ai_digests' => true, 'team_workspace' => true]],
        ];

        foreach ($plans as $plan) {
            $stripePrice = config("billing.plans.{$plan['name']}.stripe_price");

            // stripe_price_id is kept unless the environment configures one.
            Plan::updateOrCreate(['name' => $plan['name']], [
                ...$plan,
                'currency' => 'usd',
                'billing_cycle' => 'monthly',
                'trial_days' => 0,
                'is_legacy' => false,
                ...(is_string($stripePrice) && $stripePrice !== '' ? ['stripe_price_id' => $stripePrice] : []),
            ]);
        }

        Plan::query()->whereIn('name', ['Basic', 'Pro', 'Enterprise'])->update(['is_legacy' => true]);

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
