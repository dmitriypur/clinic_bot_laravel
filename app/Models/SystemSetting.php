<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * @property string $key
 * @property mixed $value
 */
class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
    ];

    protected $casts = [
        'value' => 'json',
    ];

    /**
     * Возвращает значение системной настройки.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        if (!Schema::hasTable('system_settings')) {
            return $default;
        }

        $cacheKey = static::cacheKey($key);

        $value = Cache::rememberForever($cacheKey, static function () use ($key) {
            return optional(
                static::query()
                    ->where('key', $key)
                    ->first()
            )->value;
        });

        return $value ?? $default;
    }

    /**
     * Сохраняет значение системной настройки.
     */
    public static function setValue(string $key, mixed $value): void
    {
        if (!Schema::hasTable('system_settings')) {
            return;
        }

        static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );

        Cache::forget(static::cacheKey($key));
    }

    protected static function cacheKey(string $key): string
    {
        return 'system_setting_' . $key;
    }
}
