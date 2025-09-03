# Система фильтров календаря заявок

## Обзор

Создана оптимизированная система фильтров для календаря заявок с разделением логики по ролям пользователей и улучшенной производительностью.

## Архитектура

### Основные компоненты

1. **CalendarFilterService** (`app/Services/CalendarFilterService.php`)
   - Центральный сервис для работы с фильтрами
   - Применение фильтров к запросам
   - Получение доступных данных по ролям
   - Валидация фильтров

2. **CalendarEventService** (`app/Services/CalendarEventService.php`)
   - Генерация событий календаря
   - Оптимизированные запросы
   - Кэширование данных
   - Статистика событий

3. **CalendarFilters** (`app/Filament/Resources/ApplicationResource/Widgets/CalendarFilters.php`)
   - UI компонент фильтров
   - Реактивные формы
   - Валидация на клиенте

4. **CalendarStatsWidget** (`app/Filament/Resources/ApplicationResource/Widgets/CalendarStatsWidget.php`)
   - Виджет статистики
   - Отображение метрик по фильтрам

### Трейты и оптимизации

- **HasCalendarOptimizations** (`app/Traits/HasCalendarOptimizations.php`)
  - Оптимизированные запросы
  - Предзагрузка связей
  - Кэширование
  - Мониторинг производительности

## Фильтры

### Доступные фильтры

1. **По датам**
   - Дата с
   - Дата по
   - Валидация диапазона

2. **По клиникам**
   - Множественный выбор
   - Поиск по названию
   - Фильтрация по ролям

3. **По филиалам**
   - Зависит от выбранных клиник
   - Множественный выбор
   - Поиск по названию

4. **По врачам**
   - Зависит от выбранных филиалов
   - Множественный выбор
   - Поиск по ФИО

### Разграничение по ролям

#### Super Admin
- Видит все клиники, филиалы, врачей
- Полный доступ к фильтрам
- Может создавать/редактировать/удалять заявки

#### Partner
- Видит только свою клинику
- Фильтры ограничены своей клиникой
- Может управлять заявками своей клиники

#### Doctor
- Видит только свои заявки
- Ограниченный доступ к фильтрам
- Только просмотр заявок

## Производительность

### Оптимизации

1. **Индексы базы данных**
   ```sql
   -- Для таблицы applications
   CREATE INDEX idx_appointment_datetime ON applications(appointment_datetime);
   CREATE INDEX idx_cabinet_datetime ON applications(cabinet_id, appointment_datetime);
   CREATE INDEX idx_doctor_datetime ON applications(doctor_id, appointment_datetime);
   CREATE INDEX idx_clinic_datetime ON applications(clinic_id, appointment_datetime);
   CREATE INDEX idx_branch_datetime ON applications(branch_id, appointment_datetime);
   ```

2. **Кэширование**
   - Кэш смен врачей (TTL: 5 минут)
   - Кэш заявок по слотам
   - Кэш фильтров пользователя

3. **Предзагрузка связей**
   ```php
   ->withCalendarRelations() // Для смен
   ->withApplicationRelations() // Для заявок
   ```

4. **Оптимизированные запросы**
   - Использование индексов
   - Пакетная загрузка данных
   - Чанкинг для больших объемов

### Мониторинг

```php
// Включение логирования запросов
CALENDAR_QUERY_LOGGING=true

// Включение мониторинга производительности
CALENDAR_PERFORMANCE_MONITORING=true
```

## Конфигурация

### Основные настройки (`config/calendar.php`)

```php
'working_hours' => [
    'start' => '08:00:00',
    'end' => '20:00:00',
],

'cache' => [
    'enabled' => true,
    'ttl' => 300, // 5 минут
],

'performance' => [
    'chunk_size' => 1000,
    'enable_query_logging' => false,
],
```

## Использование

### В виджете календаря

```php
class AppointmentCalendarWidget extends FullCalendarWidget
{
    protected CalendarFilterService $filterService;
    protected CalendarEventService $eventService;
    
    public function mount()
    {
        $this->filterService = app(CalendarFilterService::class);
        $this->eventService = app(CalendarEventService::class);
    }
    
    public function fetchEvents(array $fetchInfo): array
    {
        $user = auth()->user();
        return $this->eventService->generateEvents($fetchInfo, $this->filters, $user);
    }
    
    public function filtersUpdated($filters): void
    {
        $this->filters = $filters;
        $this->refreshRecords();
    }
}
```

### Команды

```bash
# Очистка кэша календаря
php artisan calendar:clear-cache

# Очистка кэша для конкретного пользователя
php artisan calendar:clear-cache --user=1

# Очистка всего кэша календаря
php artisan calendar:clear-cache --all
```

## API

### CalendarFilterService

```php
// Применение фильтров к запросу смен
$filterService->applyShiftFilters($query, $filters, $user);

// Применение фильтров к запросу заявок
$filterService->applyApplicationFilters($query, $filters, $user);

// Получение доступных клиник
$filterService->getAvailableClinics($user);

// Получение доступных филиалов
$filterService->getAvailableBranches($user, $clinicIds);

// Получение доступных врачей
$filterService->getAvailableDoctors($user, $branchIds);

// Валидация фильтров
$errors = $filterService->validateFilters($filters);
```

### CalendarEventService

```php
// Генерация событий
$events = $eventService->generateEvents($fetchInfo, $filters, $user);

// Статистика событий
$stats = $eventService->getEventStats($events);

// Группировка по дням
$groupedEvents = $eventService->groupEventsByDay($events);

// Фильтрация по типу
$occupiedEvents = $eventService->filterEventsByType($events, 'occupied');
```

## Миграции

### Добавление индексов

```bash
php artisan migrate
```

Миграция `2025_09_03_123928_add_calendar_optimization_indexes.php` добавляет необходимые индексы для оптимизации запросов.

## Мониторинг и отладка

### Логирование

```php
// Включение логирования запросов
Log::info('Calendar query', [
    'sql' => $query->toSql(),
    'bindings' => $query->getBindings(),
    'execution_time' => $executionTime,
]);
```

### Статистика производительности

```php
$stats = $this->getQueryStats($query);
// Возвращает: execution_time, memory_usage, records_count
```

## Безопасность

### Валидация

- Проверка диапазона дат
- Валидация прав доступа
- Санитизация входных данных

### Разграничение доступа

- Фильтрация по ролям на уровне запросов
- Проверка прав в UI компонентах
- Логирование действий пользователей

## Расширение

### Добавление новых фильтров

1. Добавить поле в `$filters` массив
2. Обновить логику в `CalendarFilterService`
3. Добавить UI компонент в `CalendarFilters`
4. Обновить валидацию

### Кастомные оптимизации

1. Создать новый трейт
2. Добавить методы в `HasCalendarOptimizations`
3. Применить в сервисах

## Troubleshooting

### Проблемы производительности

1. Проверить индексы: `sqlite3 database/database.sqlite ".indexes"`
2. Очистить кэш: `php artisan calendar:clear-cache --all`
3. Включить логирование: `CALENDAR_QUERY_LOGGING=true`

### Проблемы с фильтрами

1. Проверить права пользователя
2. Валидировать фильтры
3. Проверить зависимости между фильтрами

## Заключение

Новая система фильтров обеспечивает:
- Высокую производительность
- Разграничение доступа по ролям
- Оптимизированные запросы
- Кэширование данных
- Мониторинг производительности
- Легкость расширения
