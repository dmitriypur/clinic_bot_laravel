<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Получаем клиники и города
        $clinics = Clinic::all();
        $cities = City::all();
        $doctors = Doctor::all();

        if ($clinics->isEmpty() || $cities->isEmpty()) {
            echo "Нет клиник или городов для создания филиалов\n";
            return;
        }

        $branches = [
            [
                'clinic_id' => $clinics->first()->id,
                'city_id' => $cities->first()->id,
                'name' => 'Центральный филиал',
                'address' => 'ул. Центральная, д. 1',
                'phone' => '+7 (495) 123-45-67',
                'status' => 1,
            ],
            [
                'clinic_id' => $clinics->first()->id,
                'city_id' => $cities->first()->id,
                'name' => 'Северный филиал',
                'address' => 'ул. Северная, д. 25',
                'phone' => '+7 (495) 234-56-78',
                'status' => 1,
            ],
        ];

        // Если есть второй город, создаем филиал там
        if ($cities->count() > 1) {
            $branches[] = [
                'clinic_id' => $clinics->first()->id,
                'city_id' => $cities->skip(1)->first()->id,
                'name' => 'Филиал в другом городе',
                'address' => 'пр. Главный, д. 10',
                'phone' => '+7 (812) 345-67-89',
                'status' => 1,
            ];
        }

        // Если есть вторая клиника, создаем филиал для неё
        if ($clinics->count() > 1) {
            $branches[] = [
                'clinic_id' => $clinics->skip(1)->first()->id,
                'city_id' => $cities->first()->id,
                'name' => 'Филиал второй клиники',
                'address' => 'ул. Восточная, д. 15',
                'phone' => '+7 (495) 456-78-90',
                'status' => 1,
            ];
        }

        foreach ($branches as $branchData) {
            $branch = Branch::create($branchData);
            
            // Привязываем случайных врачей к филиалу
            if ($doctors->isNotEmpty()) {
                $randomDoctors = $doctors->random(min(3, $doctors->count()));
                $branch->doctors()->attach($randomDoctors->pluck('id'));
            }
        }

        echo "Создано " . count($branches) . " филиалов\n";
    }
}
