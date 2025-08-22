<?php

namespace Database\Seeders;

use App\Models\Clinic;
use App\Models\Doctor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DoctorSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $doctors = [
            [
                'last_name' => 'Иванов',
                'first_name' => 'Алексей', 
                'second_name' => 'Петрович',
                'experience' => 15,
                'age' => 42,
                'status' => 1,
                'age_admission_from' => 0,
                'age_admission_to' => 18,
                'sum_ratings' => 95,
                'count_ratings' => 20,
            ],
            [
                'last_name' => 'Петрова',
                'first_name' => 'Елена',
                'second_name' => 'Владимировна',
                'experience' => 12,
                'age' => 38,
                'status' => 1,
                'age_admission_from' => 3,
                'age_admission_to' => 16,
                'sum_ratings' => 88,
                'count_ratings' => 18,
            ],
            [
                'last_name' => 'Сидоров',
                'first_name' => 'Михаил',
                'second_name' => 'Андреевич',
                'experience' => 20,
                'age' => 50,
                'status' => 1,
                'age_admission_from' => 0,
                'age_admission_to' => 18,
                'sum_ratings' => 102,
                'count_ratings' => 22,
            ],
            [
                'last_name' => 'Козлова',
                'first_name' => 'Анна',
                'second_name' => 'Сергеевна',
                'experience' => 8,
                'age' => 33,
                'status' => 1,
                'age_admission_from' => 1,
                'age_admission_to' => 12,
                'sum_ratings' => 75,
                'count_ratings' => 15,
            ],
            [
                'last_name' => 'Волков',
                'first_name' => 'Дмитрий',
                'second_name' => 'Николаевич',
                'experience' => 25,
                'age' => 55,
                'status' => 1,
                'age_admission_from' => 0,
                'age_admission_to' => 18,
                'sum_ratings' => 115,
                'count_ratings' => 25,
            ],
        ];

        $clinics = Clinic::all();
        
        foreach ($doctors as $doctorData) {
            $doctorData['uuid'] = (string) Str::uuid();
            $doctorData['review_link'] = 'https://t.me/kidsbot?start=review_' . $doctorData['uuid'];
            
            $doctor = Doctor::create($doctorData);
            
            // Привязываем каждого врача к случайным клиникам
            $randomClinics = $clinics->random(rand(1, 3));
            $doctor->clinics()->attach($randomClinics->pluck('id'));
        }
    }
}