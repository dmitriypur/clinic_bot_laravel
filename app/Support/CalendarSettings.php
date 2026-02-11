<?php

namespace App\Support;

use App\Models\Clinic;
use App\Models\SystemSetting;
use App\Models\User;

/**
 * Управление настройками отображения календаря заявок.
 *
 * Логика:
 * - Для врачей (роль doctor) календарь всегда включен и не может быть изменен.
 * - Для партнеров и других ролей настройка хранится на уровне клиники.
 * - Для супер-администраторов и пользователей без клиники настройка хранится на уровне пользователя.
 */
class CalendarSettings
{
    private const BASE_KEY = 'dashboard_calendar_enabled';

    /**
     * Проверяет, включен ли календарь для конкретного пользователя.
     */
    public static function isEnabledForUser(?User $user): bool
    {
        if (! $user) {
            return self::valueOrDefault(self::BASE_KEY, true);
        }

        if ($user->isDoctor()) {
            return true;
        }

        if ($user->isPartner()) {
            return self::isEnabledForClinic($user->clinic);
        }

        if ($user->hasRole('admin')) {
            return self::valueOrDefault(self::resolveKeyForUser($user), true);
        }

        if ($user->isSuperAdmin()) {
            return self::valueOrDefault(self::resolveKeyForUser($user), true);
        }

        $key = self::resolveKeyForUser($user);

        $value = self::valueOrDefault($key);

        if ($value === null) {
            // Для обратной совместимости учитываем глобальное значение, если оно было сохранено раньше
            $value = self::valueOrDefault(self::BASE_KEY);
        }

        return $value ?? true;
    }

    /**
     * Сохраняет настройку отображения календаря для пользователя.
     */
    public static function setEnabledForUser(?User $user, bool $enabled): void
    {
        if (! $user) {
            SystemSetting::setValue(self::BASE_KEY, $enabled);

            return;
        }

        if ($user->isDoctor() || $user->isPartner()) {
            // Врачи и партнеры не управляют настройкой самостоятельно
            return;
        }

        $key = self::resolveKeyForUser($user);

        SystemSetting::setValue($key, $enabled);
    }

    public static function setEnabledForClinic(Clinic $clinic, bool $enabled): void
    {
        $clinic->forceFill([
            'dashboard_calendar_enabled' => $enabled,
        ])->saveQuietly();
    }

    public static function isEnabledForClinic(?Clinic $clinic): bool
    {
        if (! $clinic) {
            return true;
        }

        return (bool) ($clinic->dashboard_calendar_enabled ?? true);
    }

    /**
     * Получает ключ хранения настройки для пользователя.
     */
    protected static function resolveKeyForUser(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return self::BASE_KEY.'_user_'.$user->id;
        }

        if ($user->clinic_id) {
            return self::BASE_KEY.'_clinic_'.$user->clinic_id;
        }

        return self::BASE_KEY.'_user_'.$user->id;
    }

    /**
     * Возвращает сохраненное значение или default, если оно отсутствует.
     */
    protected static function valueOrDefault(string $key, mixed $default = null): mixed
    {
        return SystemSetting::getValue($key, $default);
    }
}
