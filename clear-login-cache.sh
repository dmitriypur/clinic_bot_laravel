#!/bin/bash

echo "Очистка кешей после удаления кастомной страницы логина..."

# Очистка всех кешей
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Пересборка кешей
php artisan config:cache
php artisan route:cache

echo "Кеши очищены! Теперь должна работать стандартная авторизация Filament."
echo "Попробуйте войти: http://147.45.184.229/admin/login"
