#!/bin/bash

echo "🔧 Исправление проблемы с таблицей applications..."
echo "================================================="

# Проверяем существование таблицы applications
echo "1. Проверка существования таблицы applications..."

# Подключаемся к БД и проверяем таблицу
TABLE_EXISTS=$(php artisan tinker --execute="echo Schema::hasTable('applications') ? 'YES' : 'NO';")

if [[ $TABLE_EXISTS == *"YES"* ]]; then
    echo "✅ Таблица applications уже существует"
    
    # Отмечаем миграцию как выполненную
    echo "2. Отметка миграции applications как выполненной..."
    php artisan migrate:fake-batch --file=2025_08_20_141213_create_applications_table.php
    
    if [ $? -eq 0 ]; then
        echo "✅ Миграция отмечена как выполненная"
    else
        echo "❌ Ошибка при отметке миграции"
        exit 1
    fi
    
else
    echo "ℹ️  Таблица applications не существует - миграция будет выполнена нормально"
fi

# Выполняем оставшиеся миграции
echo ""
echo "3. Выполнение оставшихся миграций..."
php artisan migrate --force

if [ $? -eq 0 ]; then
    echo ""
    echo "✅ Все миграции выполнены успешно!"
    echo ""
    echo "📊 Итоговое состояние:"
    php artisan migrate:status
else
    echo ""
    echo "❌ Ошибка при выполнении миграций"
    echo "Попробуйте запустить: ./deploy-migration-fix.sh"
    exit 1
fi

echo ""
echo "🎉 Проблема решена!"
