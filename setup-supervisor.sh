#!/bin/bash

# 🚀 Скрипт настройки Supervisor для Laravel очередей
# Использование: ./setup-supervisor.sh

set -e

echo "🚀 Настройка Supervisor для Laravel очередей"
echo ""

# Проверяем права root
if [ "$EUID" -ne 0 ]; then
    echo "❌ Запустите скрипт с правами root: sudo ./setup-supervisor.sh"
    exit 1
fi

# Получаем путь к проекту
PROJECT_PATH=$(pwd)
echo "📁 Путь к проекту: $PROJECT_PATH"

# Получаем пользователя веб-сервера
WEB_USER=$(ps aux | grep -E '(apache|nginx|www-data)' | head -1 | awk '{print $1}')
if [ -z "$WEB_USER" ]; then
    WEB_USER="www-data"
fi
echo "👤 Пользователь веб-сервера: $WEB_USER"

# Проверяем установку Supervisor
if ! command -v supervisorctl &> /dev/null; then
    echo "📦 Установка Supervisor..."
    apt update
    apt install -y supervisor
fi

# Создаем конфигурацию
CONFIG_FILE="/etc/supervisor/conf.d/laravel-worker.conf"
echo "📝 Создание конфигурации: $CONFIG_FILE"

cat > $CONFIG_FILE << EOF
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $PROJECT_PATH/artisan queue:work --sleep=3 --tries=3 --max-time=3600 --verbose
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=$WEB_USER
numprocs=2
redirect_stderr=true
stdout_logfile=$PROJECT_PATH/storage/logs/worker.log
stopwaitsecs=3600
EOF

echo "✅ Конфигурация создана"

# Устанавливаем права доступа
echo "🔐 Настройка прав доступа..."
chown -R $WEB_USER:$WEB_USER $PROJECT_PATH
chmod -R 755 $PROJECT_PATH/storage

# Перезагружаем Supervisor
echo "🔄 Перезагрузка Supervisor..."
supervisorctl reread
supervisorctl update

# Запускаем воркеры
echo "🚀 Запуск воркеров..."
supervisorctl start laravel-worker:*

# Проверяем статус
echo ""
echo "📊 Статус воркеров:"
supervisorctl status laravel-worker:*

echo ""
echo "✅ Настройка завершена!"
echo ""
echo "📋 Полезные команды:"
echo "  sudo supervisorctl status laravel-worker:*  # Статус воркеров"
echo "  sudo supervisorctl restart laravel-worker:* # Перезапуск воркеров"
echo "  sudo supervisorctl stop laravel-worker:*    # Остановка воркеров"
echo "  tail -f $PROJECT_PATH/storage/logs/worker.log # Логи воркеров"
echo ""
echo "🎉 Очереди настроены и запущены!"
