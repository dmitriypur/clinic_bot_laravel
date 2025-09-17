<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidTimezone implements ValidationRule
{
    /**
     * Run the validation rule.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value)) {
            $fail('Часовой пояс должен быть строкой.');
            return;
        }

        // Проверяем, что часовой пояс существует
        if (!in_array($value, timezone_identifiers_list())) {
            $fail('Указанный часовой пояс не существует.');
            return;
        }

        // Проверяем, что это российский часовой пояс
        $russianTimezones = [
            'Europe/Kaliningrad',
            'Europe/Moscow',
            'Europe/Samara',
            'Europe/Volgograd',
            'Asia/Yekaterinburg',
            'Asia/Omsk',
            'Asia/Novosibirsk',
            'Asia/Krasnoyarsk',
            'Asia/Irkutsk',
            'Asia/Chita',
            'Asia/Yakutsk',
            'Asia/Vladivostok',
            'Asia/Sakhalin',
            'Asia/Magadan',
            'Asia/Kamchatka',
            'Asia/Anadyr',
        ];

        if (!in_array($value, $russianTimezones)) {
            $fail('Поддерживаются только российские часовые пояса.');
        }
    }
}
