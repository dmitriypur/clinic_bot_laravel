#!/bin/bash

echo "🚀 Запуск очередей для экспорта..."
echo ""

# Проверяем, что Laravel установлен
if [ ! -f "artisan" ]; then
    echo "❌ Ошибка: Файл artisan не найден. Убедитесь, что вы находитесь в корневой папке Laravel проекта."
    exit 1
fi

# Проверяем настройки очередей
echo "📋 Текущие настройки очередей:"
grep "QUEUE_CONNECTION" .env || echo "QUEUE_CONNECTION не найден в .env"

echo ""
echo "🔄 Запуск обработчика очередей..."
echo "💡 Для остановки нажмите Ctrl+C"
echo ""

# Запускаем обработчик очередей
php artisan queue:work --verbose --tries=3 --timeout=90
