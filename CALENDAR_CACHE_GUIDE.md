# Руководство по кэшированию календаря

## Обзор

Система кэширования календаря автоматически очищает кэш при изменениях в связанных моделях для обеспечения актуальности данных.

## Как работает кэширование

### 1. Кэширование данных
- Кэш хранится с TTL = 5 минут (300 секунд)
- Ключи кэша имеют формат: `calendar_{cacheKey}_{userId}`
- Все ключи кэша сохраняются в `calendar_cache_keys` для последующей очистки

### 2. Автоматическая очистка кэша

Кэш автоматически очищается при следующих событиях:

#### Модель Application (Заявки)
- ✅ Создание новой заявки
- ✅ Обновление существующей заявки  
- ✅ Удаление заявки

#### Модель DoctorShift (Смены врачей)
- ✅ Создание новой смены
- ✅ Обновление существующей смены
- ✅ Удаление смены (включая soft delete)
- ✅ Восстановление смены (restore)

#### Модель Cabinet (Кабинеты)
- ✅ Создание нового кабинета
- ✅ Обновление существующего кабинета
- ✅ Удаление кабинета

### 3. Ручная очистка кэша

```bash
# Очистить весь кэш календаря
php artisan calendar:clear-cache --all

# Очистить кэш для конкретного пользователя
php artisan calendar:clear-cache --user=1

# Очистить кэш текущего пользователя
php artisan calendar:clear-cache
```

### 4. Тестирование кэширования

```bash
# Тест создания записи
php artisan calendar:test-cache --action=create

# Тест обновления записи
php artisan calendar:test-cache --action=update

# Тест удаления записи
php artisan calendar:test-cache --action=delete
```

## Конфигурация

### Настройки кэша в config/calendar.php

```php
'cache' => [
    'enabled' => true,
    'ttl' => 300, // 5 минут
    'prefix' => 'calendar_',
],
```

### Настройки производительности

```php
'performance' => [
    'chunk_size' => 1000,
    'enable_query_logging' => env('CALENDAR_QUERY_LOGGING', false),
    'enable_performance_monitoring' => env('CALENDAR_PERFORMANCE_MONITORING', false),
],
```

## Архитектура

### Trait HasCalendarOptimizations

```php
// Кэширование с автоматическим сохранением ключей
public function scopeCachedCalendarData(Builder $query, string $cacheKey, int $ttl = 300)
{
    $cacheKey = "calendar_{$cacheKey}_" . (auth()->id() ?? 'guest');
    
    // Сохраняем ключ кэша для последующей очистки
    $existingKeys = Cache::get('calendar_cache_keys', []);
    if (!in_array($cacheKey, $existingKeys)) {
        $existingKeys[] = $cacheKey;
        Cache::put('calendar_cache_keys', $existingKeys, 3600);
    }
    
    return Cache::remember($cacheKey, $ttl, function() use ($query) {
        return $query->get();
    });
}
```

### События моделей

Каждая модель с автоматической очисткой кэша содержит:

```php
protected static function boot()
{
    parent::boot();

    static::created(function ($model) {
        static::clearCalendarCache();
    });

    static::updated(function ($model) {
        static::clearCalendarCache();
    });

    static::deleted(function ($model) {
        static::clearCalendarCache();
    });
}

protected static function clearCalendarCache(): void
{
    $keys = Cache::get('calendar_cache_keys', []);
    
    foreach ($keys as $key) {
        Cache::forget($key);
    }
    
    Cache::forget('calendar_cache_keys');
}
```

## Мониторинг и отладка

### Проверка состояния кэша

```bash
# Посмотреть все ключи кэша календаря
php artisan tinker
>>> Cache::get('calendar_cache_keys', [])
```

### Логирование производительности

Включите в .env:
```
CALENDAR_QUERY_LOGGING=true
CALENDAR_PERFORMANCE_MONITORING=true
```

### Статистика запросов

```php
// В CalendarEventService
$stats = $this->getQueryStats($query);
// Возвращает: execution_time, memory_usage, records_count, query_sql, query_bindings
```

## Рекомендации

1. **TTL кэша**: 5 минут оптимально для баланса между производительностью и актуальностью
2. **Мониторинг**: Включите логирование в продакшене для отслеживания производительности
3. **Очистка**: Автоматическая очистка работает для всех CRUD операций
4. **Тестирование**: Используйте команду `calendar:test-cache` для проверки работы кэширования

## Изменения в проекте

### Добавлено в v1.1:
- ✅ Автоматическая очистка кэша для Application, DoctorShift, Cabinet
- ✅ Сохранение ключей кэша для последующей очистки
- ✅ Команда тестирования кэширования
- ✅ Документация по кэшированию

### Планируется:
- 🔄 Кэширование для других связанных моделей (Doctor, Branch, Clinic)
- 🔄 Оптимизация производительности для больших объемов данных
- 🔄 Интеграция с Redis для продакшена
