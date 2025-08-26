#!/bin/bash

# Скрипт для очистки всех кэшей Laravel на удаленном сервере
# Используйте этот скрипт после деплоя

echo "🔄 Очистка кэшей Laravel..."

# Очистка кэша приложения
php artisan cache:clear
echo "✅ Кэш приложения очищен"

# Очистка кэша конфигурации
php artisan config:clear
echo "✅ Кэш конфигурации очищен"

# Очистка кэша маршрутов (ВАЖНО для Filament!)
php artisan route:clear
echo "✅ Кэш маршрутов очищен"

# Очистка кэша представлений
php artisan view:clear
echo "✅ Кэш представлений очищен"

# Очистка compiled views и конфигурации
php artisan optimize:clear
echo "✅ Полная очистка выполнена"

# Перекомпиляция для production
echo "🔧 Оптимизация для production..."
php artisan config:cache
php artisan view:cache

# НЕ кэшируем маршруты в production если используется Filament!
# php artisan route:cache # <- Закомментировано специально

echo "✅ Все кэши очищены и приложение оптимизировано!"
echo "⚠️  ВАЖНО: Кэш маршрутов НЕ включен для корректной работы Filament"
