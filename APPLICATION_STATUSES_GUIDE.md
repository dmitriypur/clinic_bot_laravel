# Руководство по системе статусов заявок

## Обзор

Реализована новая система статусов для заявок с отдельной таблицей `application_statuses` и связью через `status_id` в таблице `applications`.

## Структура

### Таблица `application_statuses`
- `id` - первичный ключик
- `name` - название статуса (Новая, Записан, Отменен)
- `slug` - уникальный идентификатор (new, scheduled, cancelled)
- `color` - цвет для отображения в админке
- `sort_order` - порядок сортировки
- `is_active` - активен ли статус

### Связь с заявками
- В таблице `applications` добавлено поле `status_id`
- Связь через `belongsTo` в модели `Application`

## Базовые статусы

1. **Новая** (`new`) - синий цвет
2. **Записан** (`scheduled`) - зеленый цвет  
3. **Отменен** (`cancelled`) - красный цвет

## Использование в коде

### Модель ApplicationStatus
```php
// Получить активные статусы
$statuses = ApplicationStatus::getActiveStatuses();

// Получить статус по slug
$status = ApplicationStatus::getBySlug('new');

// Проверки статуса
$status->isNew();        // true для 'new'
$status->isScheduled();   // true для 'scheduled'
$status->isCancelled();  // true для 'cancelled'
```

### Модель Application
```php
// Получить название статуса
$application->getStatusName();

// Получить цвет статуса
$application->getStatusColor();

// Проверки статуса
$application->isNew();           // проверка через связь
$application->isScheduledStatus();
$application->isCancelled();

// Установить статус по slug
$application->setStatusBySlug('scheduled');

// Scope для фильтрации
Application::withStatus('new')->get();
Application::withActiveStatus()->get();
```

## Админ-панель

### Управление статусами
- Ресурс `ApplicationStatusResource` в группе "Заявки"
- Возможность создавать, редактировать и удалять статусы
- Настройка цветов и порядка сортировки

### В заявках
- Поле выбора статуса в форме создания/редактирования
- Колонка статуса в таблице с цветными бейджами
- Фильтр по статусам в расширенных фильтрах

## Миграции

1. `create_application_statuses_table` - создание таблицы статусов
2. `add_status_id_to_applications_table` - добавление связи в заявки

## Сидеры

`ApplicationStatusSeeder` - создание базовых статусов при установке

## Совместимость

Старое поле `appointment_status` сохранено для обратной совместимости, но рекомендуется использовать новую систему статусов через связь.
