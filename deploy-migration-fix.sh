#!/bin/bash

echo "🔧 Развертывание исправления миграций..."
echo "=================================="

# Проверка подключения к БД
echo "1. Проверка подключения к базе данных..."
php artisan migrate:check
if [ $? -ne 0 ]; then
    echo "❌ Ошибка подключения к БД. Проверьте настройки в .env"
    exit 1
fi

echo "✅ Подключение к БД успешно"

# Проверка состояния миграций
echo ""
echo "2. Проверка текущего состояния миграций..."
php artisan migrate:status

# Сброс миграций если есть конфликты
echo ""
read -p "⚠️  Нужно ли сбросить миграции? (y/N): " reset_migrations
if [[ $reset_migrations =~ ^[Yy]$ ]]; then
    echo "🔄 Сброс и пересоздание миграций..."
    php artisan migrate:fresh --seed
else
    echo "▶️  Выполнение обычной миграции..."
    php artisan migrate
fi

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ Миграции выполнены успешно!"
    echo ""
    echo "📊 Итоговое состояние:"
    php artisan migrate:status
else
    echo ""
    echo "❌ Ошибка при выполнении миграций"
    exit 1
fi

echo ""
echo "🎉 Развертывание завершено!"
