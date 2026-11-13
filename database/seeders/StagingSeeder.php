<?php

namespace Database\Seeders;

use App\Enums\IntegrationMode;
use App\Models\Branch;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class StagingSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('staging') || DB::connection()->getDatabaseName() !== 'adminzrenie_test') {
            throw new LogicException('Use the isolated staging database for staging data.');
        }

        DB::transaction(function () {
            $this->call(ApplicationStatusSeeder::class);

            $city = City::firstOrCreate(['name' => 'Тестовый город'], ['status' => 1]);
            $clinic = Clinic::firstOrCreate(['name' => 'Тестовая клиника'], [
                'status' => 1,
                'integration_mode' => IntegrationMode::LOCAL,
                'crm_provider' => 'none',
            ]);
            $clinic->cities()->syncWithoutDetaching($city);
            $branch = Branch::firstOrCreate([
                'city_id' => $city->id,
                'clinic_id' => $clinic->id,
                'name' => 'Тестовый филиал',
            ], [
                'address' => 'Тестовая улица, 1',
                'status' => 1,
                'slot_duration' => 30,
                'integration_mode' => IntegrationMode::LOCAL,
            ]);

            $doctor = Doctor::firstOrCreate(['first_name' => 'Тестовый', 'last_name' => 'Врач'], [
                'specialty' => 'Офтальмолог',
                'status' => 1,
                'age' => 35,
                'experience' => 10,
                'age_admission_from' => 0,
                'age_admission_to' => 130,
            ]);
            $clinic->doctors()->syncWithoutDetaching($doctor);
            $branch->doctors()->syncWithoutDetaching($doctor);
            $branch->cabinets()->firstOrCreate(['name' => 'Тестовый кабинет'], ['status' => 1]);
        });
    }
}
