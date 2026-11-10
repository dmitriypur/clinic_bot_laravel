<?php

namespace Database\Seeders;

use App\Models\Application;
use App\Models\Branch;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\DoctorShift;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class BookingIntegrationDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')
            || DB::connection()->getDriverName() !== 'sqlite'
            || City::query()->exists()) {
            throw new LogicException('Use an empty local SQLite database for the booking demo.');
        }

        DB::transaction(function (): void {
            $kirov = City::create(['name' => 'Киров', 'status' => 1]);
            $moscow = City::create(['name' => 'Москва', 'status' => 1]);
            $kirovClinic = Clinic::create(['name' => 'Зрение Киров', 'status' => 1]);
            $moscowClinic = Clinic::create(['name' => 'Зрение Москва', 'status' => 1]);

            $center = $this->branch($kirov, $kirovClinic, 'Центр', 'ул. Ленина, 1');
            $north = $this->branch($kirov, $kirovClinic, 'Север', 'ул. Московская, 2');
            $park = $this->branch($moscow, $moscowClinic, 'Татьянин парк', 'ул. Татьянин Парк, 3');

            $anna = $this->doctor('Видлацкая', 'Анна', 'Офтальмолог', 7, 12);
            $elena = $this->doctor('Видлацкая', 'Елена', 'Офтальмолог', 18);
            $irina = $this->doctor('Петрова', 'Ирина', 'Офтальмолог', 0, 7);
            $pediatrician = $this->doctor('Иванов', 'Иван', 'Педиатр', 0);
            $moscowDoctor = $this->doctor('Смирнова', 'Ольга', 'Офтальмолог', 0);
            $olderChildrenDoctor = $this->doctor('Сидорова', 'Мария', 'Офтальмолог', 8);

            $this->schedule($center, $anna, [[0, '16:00', true], [2, '17:00']]);
            $this->schedule($north, $anna, [[1, '16:00', true], [2, '15:00']]);
            $this->schedule($park, $elena, [[1, '16:00']]);
            $this->schedule($center, $irina, [
                [-1, '16:00'], [1, '14:00'], [1, '15:00'], [1, '16:00', true],
                [1, '17:00'], [1, '19:00'], [2, '16:30'],
            ]);
            $this->schedule($north, $irina, [[1, '16:30']]);
            $this->schedule($center, $pediatrician, [[1, '16:15']]);
            $this->schedule($park, $moscowDoctor, [[1, '16:00']]);
            $this->schedule($center, $olderChildrenDoctor, [[1, '16:00']]);
        });
    }

    private function branch(City $city, Clinic $clinic, string $name, string $address): Branch
    {
        $clinic->cities()->syncWithoutDetaching($city);

        return Branch::create([
            'city_id' => $city->id, 'clinic_id' => $clinic->id, 'name' => $name,
            'address' => $address, 'status' => 1, 'slot_duration' => 30,
        ]);
    }

    private function doctor(string $surname, string $name, string $specialty, int $min, int $max = 130): Doctor
    {
        return Doctor::create([
            'last_name' => $surname, 'first_name' => $name, 'specialty' => $specialty, 'status' => 1,
            'age' => 35, 'experience' => 10, 'age_admission_from' => $min, 'age_admission_to' => $max,
        ]);
    }

    private function schedule(Branch $branch, Doctor $doctor, array $slots): void
    {
        $branch->doctors()->attach($doctor);
        $branch->clinic->doctors()->syncWithoutDetaching($doctor);
        $cabinet = $branch->cabinets()->create(['name' => 'Кабинет '.$doctor->id, 'status' => 1]);
        $today = CarbonImmutable::parse(getenv('BOOKING_DEMO_DATE') ?: 'today', 'Europe/Moscow')->startOfDay();

        foreach ($slots as $slot) {
            [$offset, $time] = $slot;
            $start = $today->addDays($offset)->setTimeFromTimeString($time);
            DoctorShift::create([
                'doctor_id' => $doctor->id, 'cabinet_id' => $cabinet->id,
                'start_time' => $start->utc(), 'end_time' => $start->addMinutes(30)->utc(),
            ]);
            if (! ($slot[2] ?? false)) {
                continue;
            }

            Application::withoutEvents(fn () => Application::create([
                'city_id' => $branch->city_id, 'clinic_id' => $branch->clinic_id, 'branch_id' => $branch->id,
                'cabinet_id' => $cabinet->id, 'doctor_id' => $doctor->id,
                'appointment_datetime' => $start,
                'full_name' => 'Тестовый пациент', 'phone' => '00000000000',
            ]));
        }
    }
}
