<?php

namespace Database\Seeders;

use App\Models\City;
use App\Models\Clinic;
use Illuminate\Database\Seeder;

class ClinicSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $clinics = [
            ['name' => 'Детская глазная клиника "Взгляд"', 'status' => 1],
            ['name' => 'Офтальмологический центр "Ясный взор"', 'status' => 1],
            ['name' => 'Клиника коррекции зрения "Прозрение"', 'status' => 1],
            ['name' => 'Медицинский центр "Здоровые глазки"', 'status' => 1],
            ['name' => 'Глазная клиника для детей "Светлячок"', 'status' => 1],
        ];

        $cities = City::all();

        foreach ($clinics as $clinicData) {
            $clinic = Clinic::create($clinicData);

            // Привязываем каждую клинику к случайным городам
            $randomCities = $cities->random(rand(2, 4));
            $clinic->cities()->attach($randomCities->pluck('id'));
        }
    }
}
