<?php

namespace App\Console\Commands;

use App\Services\BookingMonitoring\BookingAvailabilityChecker;
use App\Services\BookingMonitoring\BookingMonitoringNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class CheckBookingAvailability extends Command
{
    protected $signature = 'booking:check-availability
                            {--force : Выполнить проверку, даже если мониторинг выключен}';

    protected $description = 'Проверить доступность публикации 1С для записи клиентов';

    public function handle(
        BookingAvailabilityChecker $checker,
        BookingMonitoringNotifier $notifier,
    ): int {
        if (! config('booking-monitoring.enabled') && ! $this->option('force')) {
            $this->components->info('Мониторинг записи отключён.');

            return self::SUCCESS;
        }

        $lock = Cache::lock(
            'booking-monitoring:availability-check',
            (int) config('booking-monitoring.lock_seconds', 60)
        );

        if (! $lock->get()) {
            $this->components->warn('Предыдущая проверка ещё выполняется.');

            return self::SUCCESS;
        }

        try {
            $result = $checker->check();
            $notifier->scheduledCheck($result);

            Log::log($result->available ? 'info' : 'warning', 'Результат проверки доступности записи в 1С.', [
                'available' => $result->available,
                'status_code' => $result->statusCode,
                'duration_ms' => $result->durationMs,
                'error' => $result->error,
            ]);

            if ($result->available) {
                $this->components->info("Запись доступна: HTTP {$result->statusCode}, {$result->durationMs} мс.");

                return self::SUCCESS;
            }

            $this->components->error('Запись недоступна: '.($result->error ?? 'нет ответа от сервера.'));

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }
}
