<?php

namespace App\Services\BookingMonitoring;

use App\Services\OneC\Exceptions\OneCBookingException;
use Illuminate\Support\Arr;

final class OneCBookingFailureClassifier
{
    public function isTechnicalUnavailable(OneCBookingException $exception): bool
    {
        $context = $exception->context();
        $statusCode = $this->statusCode($context);

        if ($statusCode !== null) {
            return $statusCode >= 500 || in_array($statusCode, [401, 403, 408, 429], true);
        }

        // OneCBookingService помещает сюда контекст сетевой/форматной ошибки OneCApiClient.
        return array_key_exists('api_error', $context);
    }

    public function safeReason(OneCBookingException $exception): string
    {
        $statusCode = $this->statusCode($exception->context());

        return $statusCode === null ? 'Техническая ошибка соединения с 1С' : "HTTP {$statusCode}";
    }

    private function statusCode(array $context): ?int
    {
        $value = Arr::get($context, 'api_error.status')
            ?? Arr::get($context, 'api_error.status_code')
            ?? Arr::get($context, 'status_code');

        return is_numeric($value) ? (int) $value : null;
    }
}
