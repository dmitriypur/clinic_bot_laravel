<?php

namespace App\Services;

use App\Models\City;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

class TimezoneService
{
    /**
     * Получить часовой пояс для города с кэшированием
     */
    public function getCityTimezone(int $cityId): string
    {
        return Cache::remember(
            "city_timezone_{$cityId}",
            now()->addHours(24), // Кэшируем на 24 часа
            function () use ($cityId) {
                $city = City::find($cityId);
                return $city?->timezone ?? config('app.timezone');
            }
        );
    }

    /**
     * Получить часовой пояс для пользователя с кэшированием
     */
    public function getUserTimezone(?User $user = null): string
    {
        $user = $user ?? Auth::user();
        
        if (!$user) {
            return config('app.timezone');
        }

        return Cache::remember(
            "user_timezone_{$user->id}",
            now()->addHours(12), // Кэшируем на 12 часов
            function () use ($user) {
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

                return config('app.timezone');
            }
        );
    }

    /**
     * Получить часовой пояс для клиники с кэшированием
     */
    public function getClinicTimezone(int $clinicId): string
    {
        return Cache::remember(
            "clinic_timezone_{$clinicId}",
            now()->addHours(24), // Кэшируем на 24 часа
            function () use ($clinicId) {
                $clinic = \App\Models\Clinic::with('cities')->find($clinicId);
                
                if ($clinic && $clinic->cities->isNotEmpty()) {
                    return $clinic->cities->first()->timezone;
                }

                return config('app.timezone');
            }
        );
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
     * Получить текущее время в часовом поясе города
     */
    public function nowInCityTimezone(int $cityId): Carbon
    {
        $cityTimezone = $this->getCityTimezone($cityId);
        return now()->setTimezone($cityTimezone);
    }

    /**
     * Проверить, прошло ли время в часовом поясе города
     */
    public function isPastInCityTimezone(Carbon $datetime, int $cityId): bool
    {
        $cityTimezone = $this->getCityTimezone($cityId);
        return $datetime->setTimezone($cityTimezone)->isPast();
    }

    /**
     * Получить список доступных часовых поясов России
     */
    public function getRussianTimezones(): array
    {
        return [
            'Europe/Kaliningrad' => 'Калининград (UTC+2)',
            'Europe/Moscow' => 'Москва (UTC+3)',
            'Europe/Samara' => 'Самара (UTC+4)',
            'Europe/Volgograd' => 'Волгоград (UTC+3)',
            'Asia/Yekaterinburg' => 'Екатеринбург (UTC+5)',
            'Asia/Omsk' => 'Омск (UTC+6)',
            'Asia/Novosibirsk' => 'Новосибирск (UTC+7)',
            'Asia/Krasnoyarsk' => 'Красноярск (UTC+7)',
            'Asia/Irkutsk' => 'Иркутск (UTC+8)',
            'Asia/Chita' => 'Чита (UTC+9)',
            'Asia/Yakutsk' => 'Якутск (UTC+9)',
            'Asia/Vladivostok' => 'Владивосток (UTC+10)',
            'Asia/Sakhalin' => 'Сахалин (UTC+10)',
            'Asia/Magadan' => 'Магадан (UTC+11)',
            'Asia/Kamchatka' => 'Камчатка (UTC+12)',
            'Asia/Anadyr' => 'Анадырь (UTC+12)',
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

    /**
     * Очистить кэш часового пояса для города
     */
    public function clearCityTimezoneCache(int $cityId): void
    {
        Cache::forget("city_timezone_{$cityId}");
    }

    /**
     * Очистить кэш часового пояса для клиники
     */
    public function clearClinicTimezoneCache(int $clinicId): void
    {
        Cache::forget("clinic_timezone_{$clinicId}");
    }

    /**
     * Очистить кэш часового пояса для пользователя
     */
    public function clearUserTimezoneCache(int $userId): void
    {
        Cache::forget("user_timezone_{$userId}");
    }

    /**
     * Очистить весь кэш часовых поясов
     */
    public function clearAllTimezoneCache(): void
    {
        $cities = City::pluck('id');
        foreach ($cities as $cityId) {
            $this->clearCityTimezoneCache($cityId);
        }

        $clinics = \App\Models\Clinic::pluck('id');
        foreach ($clinics as $clinicId) {
            $this->clearClinicTimezoneCache($clinicId);
        }

        // Очищаем кэш пользователей (более агрессивно)
        Cache::flush();
    }

    /**
     * Предзагрузка часовых поясов для списка городов
     */
    public function preloadCityTimezones(array $cityIds): void
    {
        foreach ($cityIds as $cityId) {
            $this->getCityTimezone($cityId);
        }
    }
}