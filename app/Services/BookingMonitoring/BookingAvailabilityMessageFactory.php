<?php

namespace App\Services\BookingMonitoring;

use Illuminate\Support\Carbon;

final class BookingAvailabilityMessageFactory
{
    public function scheduledCheck(BookingAvailabilityCheckResult $result): string
    {
        $status = $result->available ? '🟢 ЗАПИСЬ ДОСТУПНА' : '🔴 ЗАПИСЬ НЕДОСТУПНА';
        $http = $result->statusCode === null ? 'нет ответа' : "HTTP {$result->statusCode}";

        $lines = [
            $status,
            'Плановая проверка 1С',
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
     * Формирует сообщение только из технических данных — без ФИО, телефона и даты рождения пациента.
     */
    public function bookingFailure(array $context): string
    {
        $lines = [
            '🔴 ОШИБКА ЗАПИСИ КЛИЕНТА',
            'Сайт не смог передать запись в 1С.',
            'Время ошибки: '.Carbon::now()->format('d.m.Y H:i:s'),
        ];

        $labels = [
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
