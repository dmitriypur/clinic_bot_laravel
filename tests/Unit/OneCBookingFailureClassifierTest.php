<?php

namespace Tests\Unit;

use App\Services\BookingMonitoring\OneCBookingFailureClassifier;
use App\Services\OneC\Exceptions\OneCBookingException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OneCBookingFailureClassifierTest extends TestCase
{
    #[DataProvider('technicalReasons')]
    public function test_it_describes_technical_booking_failures(array $apiError, string $expectedReason): void
    {
        $exception = new OneCBookingException('Raw sensitive exception', [
            'api_error' => $apiError,
        ]);

        $reason = app(OneCBookingFailureClassifier::class)->safeReason($exception);

        $this->assertSame($expectedReason, $reason);
        $this->assertStringNotContainsString('Raw sensitive exception', $reason);
    }

    public static function technicalReasons(): array
    {
        return [
            'http 502' => [['status' => 502], 'Сервер 1С или промежуточный сервер вернул HTTP 502'],
            'http 401' => [['status' => 401], '1С отклонила авторизацию, HTTP 401'],
            'http 403' => [['status' => 403], '1С отклонила авторизацию, HTTP 403'],
            'http 408' => [['status' => 408], 'Сервер 1С сообщил о тайм-ауте, HTTP 408'],
            'http 429' => [['status' => 429], '1С временно ограничила количество запросов, HTTP 429'],
            'timeout with duration' => [
                ['error_category' => 'timeout', 'timeout_seconds' => 15],
                '1С не ответила за 15 секунд',
            ],
            'timeout without duration' => [
                ['error_category' => 'timeout'],
                'Истекло время ожидания ответа от 1С',
            ],
            'connection refused' => [
                ['error_category' => 'connection_refused'],
                'Соединение с сервером 1С отклонено',
            ],
            'dns' => [['error_category' => 'dns'], 'Не удалось определить адрес сервера 1С'],
            'tls' => [['error_category' => 'tls'], 'Ошибка защищённого соединения с сервером 1С'],
            'connection reset' => [
                ['error_category' => 'connection_reset'],
                'Соединение с 1С было прервано до получения ответа',
            ],
            'invalid response' => [
                ['error_category' => 'invalid_response'],
                '1С ответила, но вернула некорректные данные',
            ],
            'network' => [
                ['error_category' => 'network'],
                'Сетевая ошибка между сервером сайта и 1С',
            ],
            'unknown' => [[], 'Не удалось получить подтверждение записи от 1С'],
        ];
    }
}
