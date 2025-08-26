# Исправление ошибки миграций

## Проблемы

### 1. Ошибка порядка миграций
```
SQLSTATE[HY000]: General error: 1824 Failed to open the referenced table 'clinics'
```

### 2. Дублирование таблицы applications (НА СЕРВЕРЕ)
```
SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'applications' already exists
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

## Решение проблемы дублирования таблицы applications

### Быстрое исправление (рекомендуется):
```bash
./fix-applications-table.sh
```

### Ручное исправление:
1. Проверить существование таблицы:
```bash
php artisan tinker --execute="echo Schema::hasTable('applications') ? 'EXISTS' : 'NOT_EXISTS';"
```

2. Если таблица существует, отметить миграцию как выполненную:
```bash
php artisan migrate:fake-batch --file=2025_08_20_141213_create_applications_table.php
```

3. Выполнить оставшиеся миграции:
```bash
php artisan migrate --force
```

### Изменения в коде:
- Обновлена миграция `create_applications_table.php` с проверкой `Schema::hasTable()`
- Создан скрипт быстрого исправления `fix-applications-table.sh`
- Обновлен основной deployment скрипт `deploy-migration-fix.sh`

## Важно для будущих миграций
- Всегда проверяйте порядок временных меток
- Создавайте базовые таблицы раньше зависимых
- Используйте `php artisan migrate:check` перед развертыванием
- Добавляйте проверки `Schema::hasTable()` для критических таблиц
- На продакшн сервере используйте `--force` флаг для миграций
