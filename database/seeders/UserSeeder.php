<?php
namespace Database\Seeders;

use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminEmail = 'admin@admin.com';

        // Pega o plano Enterprise
        $plan = Plan::where('name', 'Enterprise')->first();

        // Cria usuário admin
        $admin = User::updateOrCreate(
            ['email' => $adminEmail],
            [
                'name'                 => 'Admin',
                'password'             => Hash::make('password'), // senha: password
                'plan_id'              => $plan ? $plan->id : null,
                'is_admin'             => true,
                'trial_ends_at'        => now()->addDays(14),
                'subscription_ends_at' => now()->addYear(),
            ]
        );

        // Vincula role Admin
        $adminRole = Role::where('name', 'Admin')->first();
        if ($adminRole) {
            $admin->roles()->syncWithoutDetaching([$adminRole->id]);
        }

        $this->command->info("Admin user created: {$adminEmail} / password");
    }
}
