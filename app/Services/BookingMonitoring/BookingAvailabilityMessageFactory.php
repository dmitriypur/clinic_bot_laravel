<?php

namespace App\Services\BookingMonitoring;

use Illuminate\Support\Carbon;

final class BookingAvailabilityMessageFactory
{
    public function scheduledCheck(BookingAvailabilityCheckResult $result): string
    {
        return $this->availabilityCheck($result, 'Плановая проверка 1С');
    }

    public function manualCheck(BookingAvailabilityCheckResult $result): string
    {
        return $this->availabilityCheck($result, 'Ручная проверка 1С');
    }

    private function availabilityCheck(BookingAvailabilityCheckResult $result, string $checkLabel): string
    {
        $status = $result->available ? '🟢 ЗАПИСЬ ДОСТУПНА' : '🔴 ЗАПИСЬ НЕДОСТУПНА';
        $http = $result->statusCode === null ? 'нет ответа' : "HTTP {$result->statusCode}";

        $lines = [
            $status,
            $checkLabel,
            'Время: '.Carbon::now()->format('d.m.Y H:i:s'),
            'Адрес: '.config('booking-monitoring.url'),
            "Ответ: {$http}",
            "Время ответа: {$result->durationMs} мс",
        ];

        if (! $result->available && $result->error) {
            $lines[] = 'Ошибка: '.$this->singleLine($result->error);
        }

        return implode("\n", $lines);
    }

    /**
     * Формирует сообщение только из явно разрешённых данных заявки.
     */
    public function bookingFailure(array $context): string
    {
        $lines = [
            '🔴 ОШИБКА ЗАПИСИ КЛИЕНТА',
            'Сайт не смог передать запись в 1С.',
            'Этап: Передача записи клиента в 1С',
            'Время ошибки: '.Carbon::now()->format('d.m.Y H:i:s'),
        ];

        $labels = [
            'patient_name' => 'Клиент',
            'phone' => 'Телефон',
            'branch' => 'Филиал',
            'doctor' => 'Врач',
            'appointment_at' => 'Желаемое время',
            'application_id' => 'Номер заявки',
            'reason' => 'Причина',
        ];

        foreach ($labels as $key => $label) {
            $value = $context[$key] ?? null;

            if ($value !== null && $value !== '') {
                $lines[] = $label.': '.$this->singleLine((string) $value);
            }
        }

        return implode("\n", $lines);
    }

    private function singleLine(string $value): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
