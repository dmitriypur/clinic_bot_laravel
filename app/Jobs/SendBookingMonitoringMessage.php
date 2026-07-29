<?php

namespace App\Jobs;

use App\Services\BookingMonitoring\BitrixMonitoringClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendBookingMonitoringMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly string $message) {}

    public function backoff(): array
    {
        return [60, 300];
    }

    public function message(): string
    {
        return $this->message;
    }

    public function handle(BitrixMonitoringClient $bitrix): void
    {
        if (! config('booking-monitoring.enabled')) {
            return;
        }

        $bitrix->send($this->message);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Не удалось отправить уведомление мониторинга записи в Bitrix24.', [
            'error' => $exception?->getMessage(),
        ]);
    }
}
