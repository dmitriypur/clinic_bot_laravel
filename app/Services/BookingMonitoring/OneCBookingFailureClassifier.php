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
        $apiError = (array) Arr::get($exception->context(), 'api_error', []);
        $statusCode = $this->statusCode($exception->context());

        if ($statusCode !== null) {
            return match (true) {
                in_array($statusCode, [401, 403], true) => "1С отклонила авторизацию, HTTP {$statusCode}",
                $statusCode === 408 => 'Сервер 1С сообщил о тайм-ауте, HTTP 408',
                $statusCode === 429 => '1С временно ограничила количество запросов, HTTP 429',
                $statusCode >= 500 => "Сервер 1С или промежуточный сервер вернул HTTP {$statusCode}",
                default => "HTTP {$statusCode}",
            };
        }

        $category = Arr::get($apiError, 'error_category');

        return match ($category) {
            'timeout' => $this->timeoutReason(Arr::get($apiError, 'timeout_seconds')),
            'connection_refused' => 'Соединение с сервером 1С отклонено',
            'dns' => 'Не удалось определить адрес сервера 1С',
            'tls' => 'Ошибка защищённого соединения с сервером 1С',
            'connection_reset' => 'Соединение с 1С было прервано до получения ответа',
            'invalid_response' => '1С ответила, но вернула некорректные данные',
            'network' => 'Сетевая ошибка между сервером сайта и 1С',
            default => 'Не удалось получить подтверждение записи от 1С',
        };
    }

    private function timeoutReason(mixed $timeout): string
    {
        if (is_numeric($timeout) && (int) $timeout > 0) {
            return '1С не ответила за '.(int) $timeout.' секунд';
        }

        return 'Истекло время ожидания ответа от 1С';
    }

    private function statusCode(array $context): ?int
    {
        $value = Arr::get($context, 'api_error.status')
            ?? Arr::get($context, 'api_error.status_code')
            ?? Arr::get($context, 'status_code');

        return is_numeric($value) ? (int) $value : null;
    }
}
