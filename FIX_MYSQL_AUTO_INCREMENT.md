# Исправление ошибки AUTO_INCREMENT для MySQL

## Проблема
На удаленном сервере с MySQL возникает ошибка при создании заявки:
```
SQLSTATE[HY000]: General error: 1364 Field 'id' doesn't have a default value
```

## Причина
Поле `id` в таблице `applications` не имеет AUTO_INCREMENT в MySQL.

## Решение

### 1. Запустить команду исправления
```bash
php artisan fix:applications-id-auto-increment
```

### 2. Альтернативное решение через SQL
Если команда не работает, выполнить напрямую в MySQL:
```sql
ALTER TABLE applications MODIFY id BIGINT AUTO_INCREMENT;
```

### 3. Проверить результат
```sql
SHOW COLUMNS FROM applications LIKE 'id';
```
Должно показать: `id bigint(20) NOT NULL AUTO_INCREMENT`

## Проверка
После исправления попробовать создать заявку через бота или админку.

## Примечание
Эта проблема возникает только на MySQL. SQLite автоматически добавляет AUTO_INCREMENT для primary key.
