<?php

namespace App\Support;

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
        if (!$user) {
            return self::valueOrDefault(self::BASE_KEY, true);
        }

        if ($user->isDoctor()) {
            return true;
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
        if (!$user) {
            SystemSetting::setValue(self::BASE_KEY, $enabled);
            return;
        }

        if ($user->isDoctor()) {
            // Врач не может отключить календарь
            return;
        }

        $key = self::resolveKeyForUser($user);

        SystemSetting::setValue($key, $enabled);
    }

    /**
     * Получает ключ хранения настройки для пользователя.
     */
    protected static function resolveKeyForUser(User $user): string
    {
        if ($user->isSuperAdmin()) {
            return self::BASE_KEY . '_user_' . $user->id;
        }

        if ($user->clinic_id) {
            return self::BASE_KEY . '_clinic_' . $user->clinic_id;
        }

        return self::BASE_KEY . '_user_' . $user->id;
    }

    /**
     * Возвращает сохраненное значение или default, если оно отсутствует.
     */
    protected static function valueOrDefault(string $key, mixed $default = null): mixed
    {
        return SystemSetting::getValue($key, $default);
    }
}

