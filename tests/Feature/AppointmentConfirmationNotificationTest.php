<?php

namespace Tests\Feature;

use App\Jobs\SendAppointmentConfirmationNotification;
use App\Models\Application;
use App\Models\ApplicationStatus;
use App\Models\Branch;
use App\Models\Cabinet;
use App\Models\City;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Services\TelegramService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class AppointmentConfirmationNotificationTest extends TestCase
{
    use RefreshDatabase;
    use MockeryPHPUnitIntegration;

    public function test_dispatches_job_when_status_becomes_confirmed(): void
    {
        Queue::fake();

        $city = City::create([
            'name' => 'Test City',
            'status' => 1,
        ]);

        $scheduledStatus = ApplicationStatus::create([
            'name' => 'Запланирован',
            'slug' => 'appointment_scheduled',
            'color' => 'blue',
            'sort_order' => 1,
            'is_active' => true,
            'type' => 'appointment',
        ]);

        $confirmedStatus = ApplicationStatus::create([
            'name' => 'Подтверждена',
            'slug' => 'appointment_confirmed',
            'color' => 'green',
            'sort_order' => 2,
            'is_active' => true,
            'type' => 'appointment',
        ]);

        $application = Application::create([
            'city_id' => $city->id,
            'full_name' => 'Иван Иванов',
            'phone' => '9999999999',
            'status_id' => $scheduledStatus->id,
            'tg_chat_id' => 123456789,
            'appointment_datetime' => now(),
        ]);

        Queue::assertNothingPushed();

        $application->status_id = $confirmedStatus->id;
        $application->save();

        Queue::assertPushed(SendAppointmentConfirmationNotification::class, function ($job) use ($application) {
            return $job instanceof SendAppointmentConfirmationNotification
                && $job->getApplicationId() === $application->id;
        });
    }

    public function test_does_not_dispatch_when_chat_id_missing(): void
    {
        Queue::fake();

        $city = City::create([
            'name' => 'No Telegram City',
            'status' => 1,
        ]);

        $confirmedStatus = ApplicationStatus::create([
            'name' => 'Подтверждена',
            'slug' => 'appointment_confirmed',
            'color' => 'green',
            'sort_order' => 1,
            'is_active' => true,
            'type' => 'appointment',
        ]);

        $application = Application::create([
            'city_id' => $city->id,
            'full_name' => 'Петр Петров',
            'phone' => '8888888888',
            'status_id' => null,
            'appointment_datetime' => now(),
        ]);

        $application->status_id = $confirmedStatus->id;
        $application->save();

        Queue::assertNothingPushed();
    }

    public function test_job_sends_message_with_appointment_details(): void
    {
        $city = City::create([
            'name' => 'Message City',
            'status' => 1,
        ]);

        $clinic = Clinic::create([
            'name' => 'Клиника №1',
            'status' => 1,
            'slot_duration' => 30,
        ]);

        $branch = Branch::create([
            'name' => 'Главный филиал',
            'clinic_id' => $clinic->id,
            'city_id' => $city->id,
            'address' => 'ул. Тестовая, д. 1',
            'status' => 1,
            'slot_duration' => 30,
        ]);

        $cabinet = Cabinet::create([
            'branch_id' => $branch->id,
            'name' => 'Кабинет 101',
            'status' => 1,
        ]);

        $doctor = Doctor::create([
            'last_name' => 'Иванов',
            'first_name' => 'Иван',
            'second_name' => 'Иванович',
            'experience' => 10,
            'age' => 40,
            'status' => 1,
            'age_admission_from' => 0,
            'age_admission_to' => 18,
        ]);

        $status = ApplicationStatus::create([
            'name' => 'Подтверждена',
            'slug' => 'appointment_confirmed',
            'color' => 'green',
            'sort_order' => 1,
            'is_active' => true,
            'type' => 'appointment',
        ]);

        $appointmentDate = now()->setHour(14)->setMinute(30)->setSecond(0);

        $application = Application::withoutEvents(function () use (
            $city,
            $clinic,
            $branch,
            $cabinet,
            $doctor,
            $status,
            $appointmentDate
        ) {
            return Application::create([
                'city_id' => $city->id,
                'clinic_id' => $clinic->id,
                'branch_id' => $branch->id,
                'cabinet_id' => $cabinet->id,
                'doctor_id' => $doctor->id,
                'full_name' => 'Мария Иванова',
                'phone' => '7777777777',
                'status_id' => $status->id,
                'tg_chat_id' => 555555,
                'appointment_datetime' => $appointmentDate,
            ]);
        });

        $mock = Mockery::mock(TelegramService::class);
        $mock->shouldReceive('sendMessage')
            ->once()
            ->with($application->tg_chat_id, Mockery::on(function (string $message) use ($clinic, $branch, $cabinet, $doctor, $appointmentDate) {
                $this->assertStringContainsString('Ваша заявка подтверждена', $message);
                $this->assertStringContainsString($appointmentDate->format('d.m.Y'), $message);
                $this->assertStringContainsString($appointmentDate->format('H:i'), $message);
                $this->assertStringContainsString($doctor->full_name, $message);
                $this->assertStringContainsString($clinic->name, $message);
                $this->assertStringContainsString($branch->name, $message);
                $this->assertStringContainsString($branch->address, $message);
                $this->assertStringContainsString($cabinet->name, $message);

                return true;
            }));

        app()->instance(TelegramService::class, $mock);

        $job = new SendAppointmentConfirmationNotification($application->id);
        $job->handle(app(TelegramService::class));
    }
}
