# Исправление ошибки миграций

## Проблема
```
SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'clinics'
```

## Причина
Миграции с одинаковыми временными метками выполнялись в неправильном порядке, что приводило к попытке создания внешнего ключа на несуществующую таблицу.

## Решение
Переименованы файлы миграций для корректного порядка выполнения:

### Исправленный порядок миграций:
1. `2025_08_20_141203_create_cities_table.php`
2. `2025_08_20_141212_create_clinics_table.php`
3. `2025_08_20_141212_create_doctors_table.php`
4. `2025_08_20_141213_create_applications_table.php` ← переименована
5. `2025_08_20_141214_create_reviews_table.php` ← переименована
6. `2025_08_20_141224_create_clinic_city_table.php`
7. `2025_08_20_141224_create_clinic_doctor_table.php`

### Зависимости внешних ключей:
- `applications` → `cities`, `clinics`, `doctors`
- `reviews` → `doctors`
- `clinic_city` → `clinics`, `cities`
- `clinic_doctor` → `clinics`, `doctors`

## Команды для развертывания

### 1. Проверка перед миграцией:
```bash
php artisan migrate:check
```

### 2. Сброс миграций (если нужно):
```bash
php artisan migrate:fresh --seed
```

### 3. Обычная миграция:
```bash
php artisan migrate
```

## Важно для будущих миграций
- Всегда проверяйте порядок временных меток
- Создавайте базовые таблицы раньше зависимых
- Используйте `php artisan migrate:check` перед развертыванием
