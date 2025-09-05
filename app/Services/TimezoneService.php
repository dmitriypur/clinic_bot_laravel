<?php

namespace App\Services;

use App\Models\City;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class TimezoneService
{
    /**
     * Получить часовой пояс для города
     */
    public function getCityTimezone(int $cityId): string
    {
        $city = City::find($cityId);
        return $city ? $city->timezone : config('app.timezone');
    }

    /**
     * Получить часовой пояс для пользователя
     */
    public function getUserTimezone(?User $user = null): string
    {
        $user = $user ?? Auth::user();
        
        if (!$user) {
            return config('app.timezone');
        }

        // Если пользователь - партнер, используем часовой пояс его клиники
        if ($user->isPartner() && $user->clinic_id) {
            return $this->getClinicTimezone($user->clinic_id);
        }

        // Если пользователь - врач, используем часовой пояс его филиала
        if ($user->isDoctor() && $user->doctor_id) {
            $doctor = $user->doctor;
            if ($doctor && $doctor->branches()->exists()) {
                $branch = $doctor->branches()->first();
                return $this->getCityTimezone($branch->city_id);
            }
        }

        // По умолчанию используем часовой пояс приложения
        return config('app.timezone');
    }

    /**
     * Получить часовой пояс для клиники
     */
    public function getClinicTimezone(int $clinicId): string
    {
        $clinic = \App\Models\Clinic::with('cities')->find($clinicId);
        
        if ($clinic && $clinic->cities->isNotEmpty()) {
            // Используем часовой пояс первого города клиники
            return $clinic->cities->first()->timezone;
        }

        return config('app.timezone');
    }

    /**
     * Конвертировать время в часовой пояс пользователя
     */
    public function convertToUserTimezone(Carbon $datetime, ?User $user = null): Carbon
    {
        $userTimezone = $this->getUserTimezone($user);
        return $datetime->setTimezone($userTimezone);
    }

    /**
     * Конвертировать время в часовой пояс города
     */
    public function convertToCityTimezone(Carbon $datetime, int $cityId): Carbon
    {
        $cityTimezone = $this->getCityTimezone($cityId);
        return $datetime->setTimezone($cityTimezone);
    }

    /**
     * Конвертировать время из часового пояса города в UTC для хранения в БД
     */
    public function convertFromCityTimezoneToUtc(Carbon $datetime, int $cityId): Carbon
    {
        $cityTimezone = $this->getCityTimezone($cityId);
        return $datetime->setTimezone($cityTimezone)->utc();
    }

    /**
     * Получить текущее время в часовом поясе пользователя
     */
    public function nowInUserTimezone(?User $user = null): Carbon
    {
        $userTimezone = $this->getUserTimezone($user);
        return now()->setTimezone($userTimezone);
    }

    /**
     * Получить текущее время в часовом поясе города
     */
    public function nowInCityTimezone(int $cityId): Carbon
    {
        $cityTimezone = $this->getCityTimezone($cityId);
        return now()->setTimezone($cityTimezone);
    }

    /**
     * Проверить, прошло ли время в часовом поясе пользователя
     */
    public function isPastInUserTimezone(Carbon $datetime, ?User $user = null): bool
    {
        $userNow = $this->nowInUserTimezone($user);
        return $datetime->isPast();
    }

    /**
     * Проверить, прошло ли время в часовом поясе города
     */
    public function isPastInCityTimezone(Carbon $datetime, int $cityId): bool
    {
        $cityNow = $this->nowInCityTimezone($cityId);
        return $datetime->isPast();
    }

    /**
     * Получить список доступных часовых поясов России
     */
    public function getRussianTimezones(): array
    {
        return [
            'Europe/Moscow' => 'Москва (UTC+3)',
            'Europe/Samara' => 'Самара (UTC+4)',
            'Asia/Yekaterinburg' => 'Екатеринбург (UTC+5)',
            'Asia/Omsk' => 'Омск (UTC+6)',
            'Asia/Krasnoyarsk' => 'Красноярск (UTC+7)',
            'Asia/Irkutsk' => 'Иркутск (UTC+8)',
            'Asia/Yakutsk' => 'Якутск (UTC+9)',
            'Asia/Vladivostok' => 'Владивосток (UTC+10)',
            'Asia/Magadan' => 'Магадан (UTC+11)',
            'Asia/Kamchatka' => 'Камчатка (UTC+12)',
        ];
    }

    /**
     * Получить часовой пояс по названию города
     */
    public function getTimezoneByCityName(string $cityName): string
    {
        $timezoneMap = [
            'Москва' => 'Europe/Moscow',
            'Санкт-Петербург' => 'Europe/Moscow',
            'Самара' => 'Europe/Samara',
            'Екатеринбург' => 'Asia/Yekaterinburg',
            'Омск' => 'Asia/Omsk',
            'Красноярск' => 'Asia/Krasnoyarsk',
            'Иркутск' => 'Asia/Irkutsk',
            'Якутск' => 'Asia/Yakutsk',
            'Владивосток' => 'Asia/Vladivostok',
            'Магадан' => 'Asia/Magadan',
            'Петропавловск-Камчатский' => 'Asia/Kamchatka',
        ];

        return $timezoneMap[$cityName] ?? 'Europe/Moscow';
    }
}
