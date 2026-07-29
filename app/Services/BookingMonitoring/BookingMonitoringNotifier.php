<?php

namespace App\Services\BookingMonitoring;

use App\Jobs\SendBookingMonitoringMessage;
use Illuminate\Support\Facades\Log;
use Throwable;

final class BookingMonitoringNotifier
{
    public function __construct(private readonly BookingAvailabilityMessageFactory $messages) {}

    public function scheduledCheck(BookingAvailabilityCheckResult $result): void
    {
        if (! config('booking-monitoring.enabled')) {
            return;
        }

        $this->dispatch($this->messages->scheduledCheck($result));
    }

    public function bookingFailure(array $safeContext): void
    {
        if (! config('booking-monitoring.enabled')) {
            return;
        }

        $this->dispatch($this->messages->bookingFailure($safeContext));
    }

    private function dispatch(string $message): void
    {
        try {
            SendBookingMonitoringMessage::dispatch($message);
        } catch (Throwable $exception) {
            Log::error('Не удалось поставить уведомление мониторинга записи в очередь.', [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
