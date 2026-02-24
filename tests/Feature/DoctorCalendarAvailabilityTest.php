<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Branch;
use App\Models\Cabinet;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\DoctorShift;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DoctorCalendarAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_calendar_availability_returns_aggregates_for_multiple_days_with_mixed_availability(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);
        Carbon::setTestNow(Carbon::create(2025, 1, 1, 8, 0, 0, 'Europe/Moscow'));

        $context = $this->createDoctorContext();

        DoctorShift::create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => Carbon::create(2025, 1, 2, 6, 0, 0, 'UTC'),
            'end_time' => Carbon::create(2025, 1, 2, 8, 0, 0, 'UTC'),
        ]);

        DoctorShift::create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => Carbon::create(2025, 1, 3, 7, 0, 0, 'UTC'),
            'end_time' => Carbon::create(2025, 1, 3, 8, 0, 0, 'UTC'),
        ]);

        Application::create([
            'city_id' => $context['city']->id,
            'clinic_id' => $context['clinic']->id,
            'branch_id' => $context['branch']->id,
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'full_name' => 'Test Patient',
            'phone' => '+70000000000',
            'appointment_datetime' => Carbon::create(2025, 1, 2, 9, 30, 0, 'Europe/Moscow'),
            'source' => Application::SOURCE_FRONTEND,
        ]);

        $response = $this->getJson('/api/v1/booking/calendar-availability?'
            .'doctor_id='.$context['doctor']->id
            .'&clinic_id='.$context['clinic']->id
            .'&branch_id='.$context['branch']->id
            .'&date_from=2025-01-02'
            .'&date_to=2025-01-03');

        $response->assertOk();

        $byDate = collect($response->json('data'))->keyBy('date');

        $this->assertCount(2, $byDate);

        $day1 = $byDate->get('2025-01-02');
        $this->assertNotNull($day1);
        $this->assertSame(4, $day1['total_slots']);
        $this->assertSame(3, $day1['available_slots']);
        $this->assertSame('09:00', $day1['first_available_time']);

        $day2 = $byDate->get('2025-01-03');
        $this->assertNotNull($day2);
        $this->assertSame(2, $day2['total_slots']);
        $this->assertSame(2, $day2['available_slots']);
        $this->assertSame('10:00', $day2['first_available_time']);
    }

    public function test_calendar_availability_includes_day_with_zero_available_slots_when_all_slots_are_in_past(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);
        Carbon::setTestNow(Carbon::create(2025, 1, 10, 12, 0, 0, 'Europe/Moscow'));

        $context = $this->createDoctorContext();

        DoctorShift::create([
            'doctor_id' => $context['doctor']->id,
            'cabinet_id' => $context['cabinet']->id,
            'start_time' => Carbon::create(2025, 1, 9, 6, 0, 0, 'UTC'),
            'end_time' => Carbon::create(2025, 1, 9, 7, 0, 0, 'UTC'),
        ]);

        $response = $this->getJson('/api/v1/booking/calendar-availability?'
            .'doctor_id='.$context['doctor']->id
            .'&clinic_id='.$context['clinic']->id
            .'&branch_id='.$context['branch']->id
            .'&date_from=2025-01-09'
            .'&date_to=2025-01-09');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');

        $entry = $response->json('data.0');
        $this->assertSame('2025-01-09', $entry['date']);
        $this->assertSame(2, $entry['total_slots']);
        $this->assertSame(0, $entry['available_slots']);
        $this->assertNull($entry['first_available_time']);
    }

    public function test_calendar_availability_returns_empty_data_when_no_slots_exist(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);
        Carbon::setTestNow(Carbon::create(2025, 1, 1, 8, 0, 0, 'Europe/Moscow'));

        $context = $this->createDoctorContext();

        $response = $this->getJson('/api/v1/booking/calendar-availability?'
            .'doctor_id='.$context['doctor']->id
            .'&clinic_id='.$context['clinic']->id
            .'&branch_id='.$context['branch']->id
            .'&date_from=2025-01-02'
            .'&date_to=2025-01-10');

        $response->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_calendar_availability_validates_nonexistent_ids(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        $response = $this->getJson('/api/v1/booking/calendar-availability?'
            .'doctor_id=999999'
            .'&clinic_id=999999'
            .'&branch_id=999999'
            .'&city_id=999999'
            .'&date_from=2025-01-01'
            .'&date_to=2025-01-02');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['doctor_id', 'clinic_id', 'branch_id', 'city_id']);
    }

    public function test_calendar_availability_rejects_period_longer_than_31_days(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);
        $context = $this->createDoctorContext();

        $response = $this->getJson('/api/v1/booking/calendar-availability?'
            .'doctor_id='.$context['doctor']->id
            .'&date_from=2025-01-01'
            .'&date_to=2025-02-02');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_calendar_availability_rejects_when_date_from_is_after_date_to(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);
        $context = $this->createDoctorContext();

        $response = $this->getJson('/api/v1/booking/calendar-availability?'
            .'doctor_id='.$context['doctor']->id
            .'&date_from=2025-01-10'
            .'&date_to=2025-01-01');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['date_to']);
    }

    public function test_calendar_availability_rejects_when_branch_does_not_belong_to_clinic(): void
    {
        config(['app.timezone' => 'Europe/Moscow']);

        $context = $this->createDoctorContext();

        $otherClinic = Clinic::create([
            'name' => 'Clinic 2',
            'status' => 1,
            'slot_duration' => 30,
        ]);

        $response = $this->getJson('/api/v1/booking/calendar-availability?'
            .'doctor_id='.$context['doctor']->id
            .'&clinic_id='.$otherClinic->id
            .'&branch_id='.$context['branch']->id
            .'&date_from=2025-01-01'
            .'&date_to=2025-01-02');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id']);
    }

    private function createDoctorContext(): array
    {
        $city = City::create([
            'name' => 'Moscow',
            'status' => 1,
        ]);

        $clinic = Clinic::create([
            'name' => 'Clinic 1',
            'status' => 1,
            'slot_duration' => 30,
        ]);

        $branch = Branch::create([
            'clinic_id' => $clinic->id,
            'city_id' => $city->id,
            'name' => 'Branch 1',
            'status' => 1,
            'slot_duration' => 30,
        ]);

        $cabinet = Cabinet::create([
            'branch_id' => $branch->id,
            'name' => '101',
            'status' => 1,
        ]);

        $doctor = Doctor::create([
            'last_name' => 'Ivanov',
            'first_name' => 'Ivan',
            'second_name' => 'Ivanovich',
            'experience' => 10,
            'age' => 40,
            'status' => 1,
            'age_admission_from' => 0,
            'age_admission_to' => 99,
            'sum_ratings' => 0,
            'count_ratings' => 0,
        ]);

        return compact('city', 'clinic', 'branch', 'cabinet', 'doctor');
    }
}
