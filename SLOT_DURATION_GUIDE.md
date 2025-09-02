# Руководство по системе длительности слотов

## Обзор

Система длительности слотов позволяет гибко настраивать временные интервалы для записи пациентов на разных уровнях: клиника, филиал и смена врача.

## Иерархия настроек

Приоритет настроек длительности слотов (от высшего к низшему):

1. **Филиал** (`Branch.slot_duration`) - настройка для всего филиала
2. **Клиника** (`Clinic.slot_duration`) - настройка по умолчанию для клиники
3. **Системное значение по умолчанию** - 30 минут

**Примечание**: Поле `slot_duration` было удалено из смен врачей (`DoctorShift`). Теперь длительность слота определяется только на уровне филиала и клиники.

## Структура базы данных

### Таблица `clinics`
```sql
ALTER TABLE clinics ADD COLUMN slot_duration INTEGER DEFAULT 30;
```

### Таблица `branches`
```sql
ALTER TABLE branches ADD COLUMN slot_duration INTEGER DEFAULT 30;
```

### Таблица `doctor_shifts`
Поле `slot_duration` было удалено. Длительность слота теперь определяется через филиал.

## Модели

### Clinic
```php
// Получить эффективную длительность слота
$clinic->getEffectiveSlotDuration(); // Возвращает slot_duration или 30 по умолчанию
```

### Branch
```php
// Получить эффективную длительность слота
$branch->getEffectiveSlotDuration(); // Возвращает slot_duration или значение из клиники
```

### DoctorShift
```php
// Получить эффективную длительность слота
$shift->getEffectiveSlotDuration(); // Возвращает значение из филиала

// Получить все слоты времени для смены
$slots = $shift->getTimeSlots();
```

## Сервис SlotService

### Основные методы

```php
use App\Services\SlotService;

$slotService = new SlotService();

// Получить длительность слота для филиала
$duration = $slotService->getBranchSlotDuration($branch);

// Получить длительность слота для клиники
$duration = $slotService->getClinicSlotDuration($clinic);

// Получить длительность слота для смены
$duration = $slotService->getShiftSlotDuration($shift);

// Разбить временной диапазон на слоты
$slots = $slotService->generateTimeSlots($startTime, $endTime, $slotDuration);

// Получить слоты для смены врача
$slots = $slotService->getShiftTimeSlots($shift);

// Проверить доступность слота
$isAvailable = $slotService->isSlotAvailable($startTime, $endTime, $shift);

// Получить доступные слоты для филиала на дату
$slots = $slotService->getAvailableSlotsForBranch($branch, $date);

// Получить стандартные варианты длительности
$durations = $slotService->getStandardSlotDurations();
```

## Стандартные варианты длительности

- 15 минут
- 30 минут (по умолчанию)
- 45 минут
- 60 минут (1 час)
- 90 минут (1.5 часа)
- 120 минут (2 часа)

## Примеры использования

### Создание клиники с 60-минутными слотами
```php
$clinic = Clinic::create([
    'name' => 'Медицинский центр',
    'status' => 1,
    'slot_duration' => 60,
]);
```

### Создание филиала с 30-минутными слотами
```php
$branch = Branch::create([
    'clinic_id' => $clinic->id,
    'city_id' => 1,
    'name' => 'Филиал на Невском',
    'status' => 1,
    'slot_duration' => 30,
]);
```

### Генерация слотов времени
```php
$startTime = Carbon::now()->startOfDay()->addHours(9); // 9:00
$endTime = Carbon::now()->startOfDay()->addHours(18); // 18:00
$slots = $slotService->generateTimeSlots($startTime, $endTime, 30);

foreach ($slots as $slot) {
    echo $slot['formatted']; // "09:00 - 09:30"
    echo $slot['duration']; // 30
}
```

## Админ-панель

### Настройка в Filament

1. **Клиники** (`/admin/clinics`):
   - Поле "Длительность слота по умолчанию"
   - Используется для всех филиалов, если у них не задана своя длительность

2. **Филиалы** (`/admin/branches`):
   - Поле "Длительность слота (минуты)"
   - Если не указано, используется настройка клиники

3. **Смены врачей** (`/admin/doctor-shifts`):
   - Поле "Длительность слота" удалено
   - Длительность слота автоматически берется из настроек филиала

## Тестирование

Запустите команду для тестирования функционала:

```bash
php artisan test:slot-duration
```

Команда создаст тестовые данные, проверит работу иерархии настроек и генерацию слотов времени.

## Форматирование времени в календаре

Время в календаре теперь отображается единообразно в формате HH:MM (24-часовой формат):
- `08:00`, `08:30`, `09:00`, `09:30` и т.д.
- Убрана неконсистентность отображения (где-то только часы, где-то с минутами)

Настройка `slotLabelFormat` в календарных виджетах:
```php
'slotLabelFormat' => [
    'hour' => '2-digit',
    'minute' => '2-digit', 
    'hour12' => false,  // 24-часовой формат
],
```

## API

Система слотов интегрирована с существующими API endpoints. При создании заявок через API учитывается длительность слотов на уровне филиала.

## Миграции

Выполнены следующие миграции:
- `2025_09_02_115034_add_slot_duration_to_branches_table.php`
- `2025_09_02_115042_add_slot_duration_to_clinics_table.php`
- `2025_09_02_120115_remove_slot_duration_from_doctor_shifts_table.php`

## Совместимость

Система полностью совместима с существующим функционалом:
- Сохранена обратная совместимость с существующими сменами врачей
- API endpoints работают без изменений
- Telegram бот продолжает работать с существующей логикой
