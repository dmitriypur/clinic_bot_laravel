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
        $this->call([
            ShieldSeeder::class,
            ApplicationStatusSeeder::class,
            CitySeeder::class,
            ClinicSeeder::class,
            DoctorSeeder::class,
            BranchSeeder::class,
        ]);
    }
}
