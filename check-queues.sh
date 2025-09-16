#!/bin/bash

# 📊 Скрипт проверки статуса очередей
# Использование: ./check-queues.sh

echo "📊 Проверка статуса очередей Laravel"
echo "=================================="
echo ""

# Проверяем настройки очередей
echo "⚙️  Настройки очередей:"
if [ -f ".env" ]; then
    QUEUE_CONNECTION=$(grep "QUEUE_CONNECTION" .env | cut -d'=' -f2)
    echo "   QUEUE_CONNECTION: $QUEUE_CONNECTION"
else
    echo "   ❌ Файл .env не найден"
fi
echo ""

# Проверяем количество задач в очереди
echo "📋 Задачи в очереди:"
if command -v php &> /dev/null; then
    JOBS_COUNT=$(php artisan tinker --execute="echo DB::table('jobs')->count();" 2>/dev/null || echo "Ошибка")
    echo "   Задач в очереди: $JOBS_COUNT"
    
    FAILED_JOBS=$(php artisan tinker --execute="echo DB::table('failed_jobs')->count();" 2>/dev/null || echo "Ошибка")
    echo "   Неудачных задач: $FAILED_JOBS"
else
    echo "   ❌ PHP не найден"
fi
echo ""

# Проверяем Supervisor (если установлен)
if command -v supervisorctl &> /dev/null; then
    echo "🔧 Supervisor статус:"
    supervisorctl status laravel-worker:* 2>/dev/null || echo "   Воркеры не настроены"
else
    echo "🔧 Supervisor: не установлен"
fi
echo ""

# Проверяем процессы queue:work
echo "🔄 Процессы queue:work:"
QUEUE_PROCESSES=$(ps aux | grep "queue:work" | grep -v grep | wc -l)
if [ $QUEUE_PROCESSES -gt 0 ]; then
    echo "   ✅ Найдено $QUEUE_PROCESSES процессов queue:work"
    ps aux | grep "queue:work" | grep -v grep | awk '{print "   PID:", $2, "CPU:", $3"%", "MEM:", $4"%", "CMD:", $11, $12, $13}'
else
    echo "   ❌ Процессы queue:work не найдены"
fi
echo ""

# Проверяем последние экспорты
echo "📤 Последние экспорты:"
if command -v php &> /dev/null; then
    php artisan tinker --execute="
    \$exports = \App\Models\Export::orderBy('created_at', 'desc')->limit(3)->get();
    if(\$exports->count() > 0) {
        foreach(\$exports as \$export) {
            echo '   ID: ' . \$export->id . ', File: ' . \$export->file_name . ', Status: ' . (\$export->completed_at ? 'Completed' : 'Pending') . ', Rows: ' . \$export->successful_rows . '/' . \$export->total_rows . PHP_EOL;
        }
    } else {
        echo '   Экспорты не найдены' . PHP_EOL;
    }
    " 2>/dev/null || echo "   Ошибка при получении экспортов"
else
    echo "   ❌ PHP не найден"
fi
echo ""

# Рекомендации
echo "💡 Рекомендации:"
if [ "$QUEUE_CONNECTION" = "sync" ]; then
    echo "   ⚠️  Используются синхронные очереди (только для разработки)"
elif [ "$QUEUE_CONNECTION" = "database" ]; then
    if [ $QUEUE_PROCESSES -eq 0 ]; then
        echo "   🚀 Запустите очереди: ./start-queues.sh"
        echo "   🔧 Или настройте Supervisor: sudo ./setup-supervisor.sh"
    else
        echo "   ✅ Очереди работают нормально"
    fi
else
    echo "   ❓ Неизвестный тип очередей: $QUEUE_CONNECTION"
fi

echo ""
echo "🎯 Готово!"
