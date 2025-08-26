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

# Попытка безопасной миграции
echo ""
echo "3. Попытка безопасной миграции..."
php artisan migrate --force

# Если ошибка - предлагаем варианты решения
if [ $? -ne 0 ]; then
    echo ""
    echo "❌ Ошибка миграции! Выберите действие:"
    echo "1) Пропустить конфликтующие таблицы и продолжить (рекомендуется)"
    echo "2) Сбросить все миграции и пересоздать (ОПАСНО - потеря данных)"
    echo "3) Отмена"
    echo ""
    read -p "Ваш выбор (1-3): " choice
    
    case $choice in
        1)
            echo "▶️  Отметка проблемных миграций как выполненных..."
            # Отмечаем миграцию applications как выполненную если таблица уже существует
            php artisan migrate:fake-batch --file=2025_08_20_141213_create_applications_table.php
            echo "🔄 Повторная попытка миграции..."
            php artisan migrate --force
            ;;
        2)
            echo "🔄 Сброс и пересоздание миграций..."
            read -p "⚠️  ВНИМАНИЕ: Это удалит все данные! Продолжить? (y/N): " confirm
            if [[ $confirm =~ ^[Yy]$ ]]; then
                php artisan migrate:fresh --seed --force
            else
                echo "❌ Операция отменена"
                exit 1
            fi
            ;;
        3)
            echo "❌ Операция отменена"
            exit 1
            ;;
        *)
            echo "❌ Неверный выбор"
            exit 1
            ;;
    esac
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
