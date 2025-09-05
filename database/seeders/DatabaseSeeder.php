<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
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
            CitySeeder::class,
            ClinicSeeder::class,
            DoctorSeeder::class,
            BranchSeeder::class,
        ]);

        // Создаем админ-пользователя
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@admin.ru',
            'password' => bcrypt('password'),
        ]);
        
        // Назначаем роль super_admin
        $admin->assignRole('super_admin');
    }
}
