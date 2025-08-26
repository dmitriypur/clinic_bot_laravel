# Исправление ошибки "Class 'Inertia\Inertia' not found" на удаленном сервере

## Проблема
После деплоя на удаленный сервер возникает ошибка:
```
Class "Inertia\Inertia" not found
```

## Причина
Основные причины:
1. ❌ Отсутствует middleware для Inertia.js в bootstrap/app.php
2. ❌ Не установлены зависимости Composer
3. ❌ Проблемы с autoload
4. ❌ Неправильные права доступа

## Решение

### Шаг 1: Проверка конфигурации (уже исправлено в проекте)
Убедитесь что в `bootstrap/app.php` есть middleware:
```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        \App\Http\Middleware\HandleInertiaRequests::class,
    ]);
    
    $middleware->validateCsrfTokens(except: [
        'botman',
        'api/*',
    ]);
})
```

### Шаг 2: Диагностика на удаленном сервере
Загрузите и запустите скрипт диагностики:
```bash
# На удаленном сервере в корне проекта
./debug-inertia.sh
```

### Шаг 3: Исправление проблем
Запустите скрипт исправления:
```bash
# На удаленном сервере в корне проекта
./fix-inertia.sh
```

### Шаг 4: Ручное исправление (если скрипт не помог)

1. **Переустановка зависимостей:**
```bash
rm -rf vendor/
rm -f composer.lock
composer install --no-dev --optimize-autoloader
```

2. **Очистка всех кэшей:**
```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

3. **Проверка прав доступа:**
```bash
chmod -R 775 storage/
chmod -R 775 bootstrap/cache/
chown -R www-data:www-data storage/
chown -R www-data:www-data bootstrap/cache/
```

4. **Проверка переменных окружения:**
```bash
# Убедитесь что .env настроен правильно
APP_ENV=production
APP_DEBUG=false
```

### Шаг 5: Проверка результата
```bash
php -r "
require 'vendor/autoload.php';
if (class_exists('Inertia\Inertia')) {
    echo '✅ Inertia\Inertia найден!';
} else {
    echo '❌ Проблема не решена';
}
"
```

## Профилактика

### При каждом деплое выполняйте:
```bash
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Настройка веб-сервера
Убедитесь что веб-сервер (Apache/Nginx) настроен правильно:
- Корневая директория указывает на `/public`
- PHP имеет права на запись в `storage/` и `bootstrap/cache/`

## Дополнительная информация

### Версии пакетов в проекте:
- Laravel: ^11.45.2
- inertiajs/inertia-laravel: ^2.0
- @inertiajs/vue3: ^2.1.2
- Vue.js: ^3.5.20

### Структура файлов Inertia:
```
app/Http/Middleware/HandleInertiaRequests.php  ✅
resources/views/app.blade.php                 ✅ 
resources/js/app.js                          ✅
package.json (с @inertiajs/vue3)             ✅
vite.config.js (с vue plugin)               ✅
```

### Команды Artisan для Inertia:
```bash
php artisan inertia:middleware    # Создать middleware
php artisan inertia:start-ssr    # Запустить SSR сервер
php artisan inertia:stop-ssr     # Остановить SSR сервер
php artisan inertia:check-ssr    # Проверить статус SSR
```

## Контакты
Если проблема не решается, проверьте:
1. Логи Laravel в `storage/logs/`
2. Логи веб-сервера
3. Версию PHP (требуется PHP ^8.2)
