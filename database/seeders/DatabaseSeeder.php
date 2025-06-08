<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Chama os Seeders que você já tem
        $this->call([
            InitialSaasSeeder::class,
            UserSeeder::class,
        ]);
    }
}
