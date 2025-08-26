# Исправление ошибки "POST method is not supported for route admin/login"

## Проблема
После деплоя на удаленный сервер админка не работает с ошибкой:
```
The POST method is not supported for route admin/login. Supported methods: GET, HEAD.
```

## Причина
Laravel закэшировал маршруты **ДО** регистрации маршрутов Filament, поэтому POST-маршруты админки недоступны.

## ✅ РЕШЕНИЕ

### На удаленном сервере выполните:

```bash
# Вариант 1: Используйте готовый скрипт
./clear-cache.sh

# Вариант 2: Выполните команды вручную
php artisan cache:clear
php artisan config:clear  
php artisan route:clear
php artisan view:clear
php artisan optimize:clear

# Оптимизация для production (БЕЗ кэша маршрутов!)
php artisan config:cache
php artisan view:cache
# НЕ запускайте php artisan route:cache с Filament!
```

### Важные моменты:

1. **НЕ используйте `php artisan route:cache` с Filament** - это ломает динамические маршруты админки
2. **Всегда очищайте кэш после деплоя** новых версий с изменениями в AdminPanelProvider
3. **Проверьте права доступа** к папкам cache и storage на сервере

### Автоматизация деплоя:

Добавьте в ваш деплой-скрипт:
```bash
# После git pull и composer install
php artisan migrate --force
./clear-cache.sh
```

### Проверка:

После выполнения команд:
1. Откройте `/admin` - должна открыться форма логина
2. Попробуйте войти с admin@admin.ru / password
3. Проверьте логи: `php artisan log:clear && tail -f storage/logs/laravel.log`

## Дополнительная диагностика

Если проблема остается:

```bash
# Проверить маршруты
php artisan route:list | grep admin

# Проверить конфигурацию Filament
php artisan about

# Права доступа
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
```
