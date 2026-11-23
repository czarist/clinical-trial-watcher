<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\RoleName;
use App\Models\Plan;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Local development administrator. Never use these credentials outside local development.
 */
final class UserSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'admin@admin.com';

        $admin = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => 'password',
                'plan_id' => Plan::where('name', Plan::FREE)->value('id'),
                'is_admin' => true,
            ],
        );

        if ($admin->email_verified_at === null) {
            $admin->markEmailAsVerified();
        }

        $role = Role::where('name', RoleName::Admin->value)->first();

        if ($role !== null) {
            $admin->roles()->syncWithoutDetaching([$role->id]);
        }

        $this->command?->info("Admin user: {$email} / password (local development only)");
    }
}
