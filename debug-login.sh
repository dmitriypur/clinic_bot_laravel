#!/bin/bash

echo "=== ДИАГНОСТИКА ПРОБЛЕМЫ С АВТОРИЗАЦИЕЙ ==="

echo "1. Проверка маршрутов admin:"
php artisan route:list | grep admin

echo -e "\n2. Проверка POST маршрутов:"
php artisan route:list --method=POST | head -10

echo -e "\n3. Проверка Livewire маршрутов:"
php artisan route:list | grep livewire

echo -e "\n4. Проверка кешей:"
php artisan about | grep -A10 "Cache"

echo -e "\n5. Проверка прав доступа:"
ls -la storage/ | head -5
ls -la bootstrap/cache/ | head -5

echo -e "\n6. Проверка .env переменных:"
echo "APP_URL: $(grep APP_URL .env)"
echo "APP_ENV: $(grep APP_ENV .env)"
echo "APP_DEBUG: $(grep APP_DEBUG .env)"

echo -e "\n7. Проверка веб-сервера (если Apache):"
if command -v apache2ctl &> /dev/null; then
    echo "Apache запущен"
    apache2ctl -S | grep "Main DocumentRoot" || echo "Не удалось определить DocumentRoot"
fi

echo -e "\n8. Проверка логов Laravel:"
if [ -f "storage/logs/laravel.log" ]; then
    echo "Последние 5 строк из логов:"
    tail -5 storage/logs/laravel.log
else
    echo "Логи Laravel не найдены"
fi

echo -e "\n=== КОМАНДЫ ДЛЯ ИСПРАВЛЕНИЯ ==="
echo "Выполни эти команды на сервере:"
echo "php artisan optimize:clear"
echo "php artisan config:cache"
echo "php artisan route:cache"
echo "chmod -R 755 storage/"
echo "chmod -R 755 bootstrap/cache/"
