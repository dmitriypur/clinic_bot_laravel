<?php

namespace Tests\Unit;

use App\Jobs\SendBookingMonitoringMessage;
use App\Services\BookingMonitoring\BitrixMonitoringClient;
use App\Services\BookingMonitoring\BookingAvailabilityCheckResult;
use App\Services\BookingMonitoring\BookingMonitoringNotifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BookingMonitoringNotifierTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['booking-monitoring.enabled' => true]);
    }

    public function test_it_queues_the_result_of_every_successful_scheduled_check(): void
    {
        Queue::fake();

        app(BookingMonitoringNotifier::class)->scheduledCheck(
            new BookingAvailabilityCheckResult(true, 200, 37)
        );

        Queue::assertPushed(SendBookingMonitoringMessage::class, function ($job): bool {
            return str_contains($job->message(), 'ДОСТУПНА')
                && str_contains($job->message(), 'HTTP 200')
                && str_contains($job->message(), '37 мс');
        });
    }

    public function test_it_queues_a_clearly_labeled_manual_check_result(): void
    {
        Queue::fake();

        app(BookingMonitoringNotifier::class)->manualCheck(
            new BookingAvailabilityCheckResult(true, 200, 37)
        );

        Queue::assertPushed(SendBookingMonitoringMessage::class, function ($job): bool {
            return str_contains($job->message(), 'Ручная проверка 1С')
                && str_contains($job->message(), 'ДОСТУПНА')
                && str_contains($job->message(), 'HTTP 200')
                && str_contains($job->message(), '37 мс');
        });
    }

    public function test_it_queues_a_safe_booking_failure_without_patient_personal_data(): void
    {
        Queue::fake();

        app(BookingMonitoringNotifier::class)->bookingFailure([
            'branch' => 'Филиал на Ленина',
            'doctor' => 'Иванов И.И.',
            'appointment_at' => '29.07.2026 12:30',
            'reason' => 'HTTP 503',
        ]);

        Queue::assertPushed(SendBookingMonitoringMessage::class, function ($job): bool {
            return str_contains($job->message(), 'ОШИБКА ЗАПИСИ КЛИЕНТА')
                && str_contains($job->message(), 'Этап: Передача записи клиента в 1С')
                && str_contains($job->message(), 'Филиал на Ленина')
                && str_contains($job->message(), 'HTTP 503');
        });
    }

    public function test_booking_failure_message_does_not_expose_secrets_or_patient_data(): void
    {
        Queue::fake();

        app(BookingMonitoringNotifier::class)->bookingFailure([
            'branch' => 'Филиал на Ленина',
            'doctor' => 'Иванов И.И.',
            'appointment_at' => '29.07.2026 12:30',
            'application_id' => 123,
            'reason' => 'Сетевая ошибка между сервером сайта и 1С',
            'url' => 'https://onec.example/secret-handler',
            'token' => 'super-secret-token',
            'response_body' => 'patient Иван Петров phone 79990000000',
            'patient_name' => 'Иван Петров',
            'phone' => '79990000000',
        ]);

        Queue::assertPushed(SendBookingMonitoringMessage::class, function ($job): bool {
            $message = $job->message();

            return str_contains($message, 'Сетевая ошибка между сервером сайта и 1С')
                && ! str_contains($message, 'secret-handler')
                && ! str_contains($message, 'super-secret-token')
                && ! str_contains($message, 'Иван Петров')
                && ! str_contains($message, '79990000000');
        });
    }

    public function test_the_delivery_job_has_retries_and_backoff(): void
    {
        $job = new SendBookingMonitoringMessage('Сообщение');

        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
    }

    public function test_disabled_monitoring_neither_queues_nor_delivers_messages(): void
    {
        config(['booking-monitoring.enabled' => false]);
        Queue::fake();
        Http::fake();

        app(BookingMonitoringNotifier::class)->scheduledCheck(
            new BookingAvailabilityCheckResult(true, 200, 10)
        );
        app(BookingMonitoringNotifier::class)->manualCheck(
            new BookingAvailabilityCheckResult(true, 200, 10)
        );
        (new SendBookingMonitoringMessage('Старое сообщение'))
            ->handle(app(BitrixMonitoringClient::class));

        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
