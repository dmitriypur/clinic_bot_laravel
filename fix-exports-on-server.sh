#!/bin/bash

# 🔧 Скрипт быстрого исправления таблицы exports на сервере
# Использование: ./fix-exports-on-server.sh

echo "🔧 Исправление таблицы exports на сервере"
echo "========================================"
echo ""

# Проверяем, что мы в папке Laravel
if [ ! -f "artisan" ]; then
    echo "❌ Ошибка: Файл artisan не найден. Убедитесь, что вы находитесь в корневой папке Laravel проекта."
    exit 1
fi

echo "📁 Текущая папка: $(pwd)"
echo ""

# Проверяем подключение к базе данных
echo "🔍 Проверка подключения к базе данных..."
if php artisan tinker --execute="echo 'DB connection: OK';" 2>/dev/null; then
    echo "✅ Подключение к базе данных работает"
else
    echo "❌ Ошибка подключения к базе данных"
    echo "💡 Проверьте настройки в .env файле"
    exit 1
fi
echo ""

# Проверяем и создаем таблицу exports
echo "🔍 Проверка таблицы exports..."
if php artisan exports:check-table 2>/dev/null; then
    echo "✅ Таблица exports уже существует и корректна"
else
    echo "❌ Таблица exports отсутствует или некорректна"
    echo "🔧 Создание таблицы exports..."
    
    if php artisan exports:check-table --create 2>/dev/null; then
        echo "✅ Таблица exports создана успешно"
    else
        echo "❌ Ошибка при создании таблицы exports"
        echo "💡 Попробуйте выполнить: php artisan migrate"
        exit 1
    fi
fi
echo ""

# Проверяем модель Export
echo "🔍 Проверка модели Export..."
if php artisan tinker --execute="echo 'Export model: '; \$export = new \App\Models\Export(); echo 'OK';" 2>/dev/null; then
    echo "✅ Модель Export работает корректно"
else
    echo "❌ Ошибка с моделью Export"
    echo "💡 Проверьте файл app/Models/Export.php"
    exit 1
fi
echo ""

# Создаем тестовый экспорт
echo "🧪 Создание тестового экспорта..."
TEST_EXPORT_ID=$(php artisan tinker --execute="
\$export = new \App\Models\Export();
\$export->file_disk = 'local';
\$export->file_name = 'test-server-export';
\$export->exporter = 'App\Filament\Exports\ApplicationExporter';
\$export->processed_rows = 1;
\$export->total_rows = 1;
\$export->successful_rows = 1;
\$export->user_id = 1;
\$export->completed_at = now();
\$export->save();
echo \$export->id;
" 2>/dev/null)

if [ ! -z "$TEST_EXPORT_ID" ]; then
    echo "✅ Тестовый экспорт создан (ID: $TEST_EXPORT_ID)"
    
    # Удаляем тестовый экспорт
    php artisan tinker --execute="\App\Models\Export::find($TEST_EXPORT_ID)->delete();" 2>/dev/null
    echo "🗑️  Тестовый экспорт удален"
else
    echo "❌ Ошибка при создании тестового экспорта"
    exit 1
fi
echo ""

# Проверяем очереди
echo "🔍 Проверка настроек очередей..."
QUEUE_CONNECTION=$(grep "QUEUE_CONNECTION" .env | cut -d'=' -f2)
echo "📋 QUEUE_CONNECTION: $QUEUE_CONNECTION"

if [ "$QUEUE_CONNECTION" = "sync" ]; then
    echo "⚠️  Используются синхронные очереди (только для разработки)"
elif [ "$QUEUE_CONNECTION" = "database" ]; then
    echo "✅ Используются очереди базы данных"
    echo "💡 Убедитесь, что запущены воркеры: php artisan queue:work"
else
    echo "❓ Неизвестный тип очередей: $QUEUE_CONNECTION"
fi
echo ""

# Финальная проверка
echo "🎯 Финальная проверка..."
if php artisan exports:check-table 2>/dev/null; then
    echo "✅ Все проверки пройдены успешно!"
    echo ""
    echo "🎉 Таблица exports готова к использованию!"
    echo ""
    echo "📋 Следующие шаги:"
    echo "   1. Убедитесь, что очереди запущены"
    echo "   2. Протестируйте экспорт в админке"
    echo "   3. Добавьте команду в CI/CD для будущих деплоев"
else
    echo "❌ Финальная проверка не пройдена"
    exit 1
fi
