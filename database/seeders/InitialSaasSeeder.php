<?php

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Role;
use App\Models\Permission;
use Illuminate\Database\Seeder;

class InitialSaasSeeder extends Seeder
{
    public function run(): void
    {
        // Criar planos
        $plans = [
            ['name' => 'Basic', 'price' => 0.00, 'billing_cycle' => 'monthly', 'features' => json_encode(['alerts_limit' => 5])],
            ['name' => 'Pro', 'price' => 29.99, 'billing_cycle' => 'monthly', 'features' => json_encode(['alerts_limit' => 50])],
            ['name' => 'Enterprise', 'price' => 99.99, 'billing_cycle' => 'monthly', 'features' => json_encode(['alerts_limit' => 9999])],
        ];

        foreach ($plans as $planData) {
            Plan::updateOrCreate(['name' => $planData['name']], $planData);
        }

        // Criar roles
        $roles = [
            ['name' => 'Admin', 'description' => 'Administrator role'],
            ['name' => 'User', 'description' => 'Regular user'],
        ];

        foreach ($roles as $roleData) {
            Role::updateOrCreate(['name' => $roleData['name']], $roleData);
        }

        // Criar permissions básicas
        $permissions = [
            ['name' => 'manage_users', 'description' => 'Manage users'],
            ['name' => 'manage_plans', 'description' => 'Manage subscription plans'],
            ['name' => 'manage_roles', 'description' => 'Manage roles and permissions'],
            ['name' => 'view_clinical_trials', 'description' => 'View clinical trials'],
            ['name' => 'create_alerts', 'description' => 'Create alerts'],
        ];

        foreach ($permissions as $permissionData) {
            Permission::updateOrCreate(['name' => $permissionData['name']], $permissionData);
        }

        // Vincular todas as permissions ao role Admin
        $adminRole = Role::where('name', 'Admin')->first();
        $allPermissions = Permission::all();
        $adminRole->permissions()->sync($allPermissions->pluck('id'));

        // Role User só terá algumas permissions básicas
        $userRole = Role::where('name', 'User')->first();
        $userPermissions = Permission::whereIn('name', ['view_clinical_trials', 'create_alerts'])->get();
        $userRole->permissions()->sync($userPermissions->pluck('id'));

        $this->command->info('Plans, Roles, and Permissions seeded successfully!');
    }
}
