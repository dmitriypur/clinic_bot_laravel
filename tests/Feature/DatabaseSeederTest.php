<?php

namespace Tests\Feature;

use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    public function test_default_seeding_does_not_create_a_user(): void
    {
        foreach ([
            '0001_01_01_000000_create_users_table.php',
            '2025_08_20_141203_create_cities_table.php',
            '2025_08_20_141212_create_clinics_table.php',
            '2025_08_20_141212_create_doctors_table.php',
            '2025_08_20_141224_create_clinic_city_table.php',
            '2025_08_20_141224_create_clinic_doctor_table.php',
            '2025_08_22_171142_create_permission_tables.php',
            '2025_08_28_185204_create_branches_table.php',
            '2025_08_28_185223_create_branch_doctor_table.php',
            '2025_09_12_115430_create_application_statuses_table.php',
            '2025_10_10_144936_add_type_to_application_statuses_table.php',
        ] as $migration) {
            (require database_path('migrations/'.$migration))->up();
        }

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('roles', ['name' => 'super_admin']);
        $this->assertDatabaseCount('users', 0);
    }
}
