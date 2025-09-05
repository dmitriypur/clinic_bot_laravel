#!/bin/bash

# Скрипт автоматического деплоя Laravel приложения
# Использование: ./deploy.sh [version]

set -e  # Прерывать выполнение при ошибках

# Конфигурация
APP_DIR="/var/www/medical-center"
BACKUP_DIR="/var/www/backups"
TEMP_DIR="/tmp/deploy-$(date +%s)"
BRANCH="main"  # или master
REPO_URL="https://github.com/your-username/adminzrenie-laravel.git"

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Функция логирования
log() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] $1${NC}"
}

error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ERROR: $1${NC}"
    exit 1
}

warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] WARNING: $1${NC}"
}

# Проверка прав доступа
check_permissions() {
    log "Проверка прав доступа..."
    
    if [ ! -w "$APP_DIR" ]; then
        error "Нет прав записи в $APP_DIR"
    fi
    
    if ! command -v php &> /dev/null; then
        error "PHP не установлен"
    fi
    
    if ! command -v composer &> /dev/null; then
        error "Composer не установлен"
    fi
    
    if ! command -v npm &> /dev/null; then
        error "npm не установлен"
    fi
}

# Создание резервной копии
create_backup() {
    log "Создание резервной копии..."
    
    mkdir -p "$BACKUP_DIR"
    BACKUP_NAME="medical-center-backup-$(date +%Y%m%d_%H%M%S)"
    
    if [ -d "$APP_DIR" ]; then
        cp -r "$APP_DIR" "$BACKUP_DIR/$BACKUP_NAME"
        log "Резервная копия создана: $BACKUP_DIR/$BACKUP_NAME"
    else
        warning "Директория приложения не найдена для бэкапа"
    fi
}

# Включение режима обслуживания
enable_maintenance() {
    log "Включение режима обслуживания..."
    
    if [ -f "$APP_DIR/artisan" ]; then
        cd "$APP_DIR"
        php artisan down --retry=60
    else
        warning "artisan не найден, режим обслуживания не включен"
    fi
}

# Отключение режима обслуживания
disable_maintenance() {
    log "Отключение режима обслуживания..."
    
    if [ -f "$APP_DIR/artisan" ]; then
        cd "$APP_DIR"
        php artisan up
    fi
}

# Обновление кода через git pull
update_code() {
    log "Обновление кода через git..."
    
    cd "$APP_DIR"
    
    # Обновляем код
    git fetch origin
    git reset --hard origin/$BRANCH
    
    log "Код обновлен"
}

# Установка зависимостей
install_dependencies() {
    log "Установка зависимостей..."
    
    cd "$APP_DIR"
    
    # Composer зависимости
    composer install --no-dev --optimize-autoloader --no-interaction
    
    # NPM зависимости и сборка (с принудительным разрешением конфликтов)
    npm ci --legacy-peer-deps
    npm run build
    
    log "Зависимости установлены"
}

# Настройка прав доступа
setup_permissions() {
    log "Настройка прав доступа..."
    
    cd "$APP_DIR"
    
    # Владелец и группа (используем laravel пользователя)
    chown -R laravel:www-data "$APP_DIR"
    
    # Права на файлы и директории
    find "$APP_DIR" -type f -exec chmod 644 {} \;
    find "$APP_DIR" -type d -exec chmod 755 {} \;
    
    # Специальные права для storage и cache
    chmod -R 775 "$APP_DIR/storage"
    chmod -R 775 "$APP_DIR/bootstrap/cache"
    
    log "Права доступа настроены"
}



# Выполнение миграций и кеширование
run_laravel_commands() {
    log "Выполнение Laravel команд..."
    
    cd "$APP_DIR"
    
    # Миграции
    php artisan migrate --force
    
    # Кеширование
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
    
    # Перезапуск очередей
    php artisan queue:restart
    
    log "Laravel команды выполнены"
}

# Перезапуск сервисов
restart_services() {
    log "Перезапуск сервисов..."
    
    # Перезапуск PHP-FPM
    if command -v systemctl >/dev/null 2>&1; then
        # Проверяем какая версия PHP установлена
        if systemctl is-active --quiet php8.3-fpm; then
            systemctl reload php8.3-fpm
            log "PHP 8.3-FPM перезапущен"
        elif systemctl is-active --quiet php8.2-fpm; then
            systemctl reload php8.2-fpm
            log "PHP 8.2-FPM перезапущен"
        else
            warning "PHP-FPM не найден или не запущен"
        fi
    fi
    
    # Перезапуск Supervisor воркеров (если установлен)
    if command -v supervisorctl >/dev/null 2>&1; then
        supervisorctl restart laravel-worker:* || log "Supervisor воркеры не настроены"
        log "Supervisor воркеры перезапущены"
    else
        warning "Supervisor не установлен, пропускаем перезапуск воркеров"
    fi
    
    # Очистка Redis кеша (если нужно)
    # redis-cli FLUSHDB
    
    log "Сервисы перезапущены"
}

# Проверка здоровья приложения
health_check() {
    log "Проверка работоспособности..."
    
    cd "$APP_DIR"
    
    # Проверяем что Laravel работает
    if php artisan about > /dev/null 2>&1; then
        log "Laravel приложение работает корректно"
    else
        error "Laravel приложение не отвечает!"
    fi
    
    # Проверяем доступность через HTTP (замените на ваш домен)
    # if curl -f -s https://your-domain.com > /dev/null; then
    #     log "Веб-сайт доступен"
    # else
    #     error "Веб-сайт недоступен!"
    # fi
}

# Очистка
cleanup() {
    log "Очистка временных файлов..."
    
    # Удаляем временные директории
    if [ -d "$TEMP_DIR" ]; then
        rm -rf "$TEMP_DIR"
    fi
    
    # Удаляем старые бэкапы (оставляем только последние 5)
    if [ -d "$BACKUP_DIR" ]; then
        cd "$BACKUP_DIR"
        ls -1t | tail -n +6 | xargs -r rm -rf
    fi
    
    log "Очистка завершена"
}

# Откат к предыдущей версии
rollback() {
    error "Деплой неудачен, выполняем откат..."
    
    disable_maintenance
    
    # Находим последний бэкап
    LATEST_BACKUP=$(ls -1t "$BACKUP_DIR" | head -n 1)
    
    if [ -n "$LATEST_BACKUP" ]; then
        log "Восстановление из бэкапа: $LATEST_BACKUP"
        
        # Удаляем неудачную версию
        rm -rf "$APP_DIR"
        
        # Восстанавливаем бэкап
        cp -r "$BACKUP_DIR/$LATEST_BACKUP" "$APP_DIR"
        
        # Перезапускаем сервисы
        restart_services
        
        log "Откат завершен"
    else
        error "Бэкап для отката не найден!"
    fi
}

# Основная функция деплоя
main() {
    log "=== Начало деплоя Medical Center ==="
    
    # Trap для отката при ошибке
    trap rollback ERR
    
    check_permissions
    create_backup
    enable_maintenance
    update_code
    install_dependencies
    setup_permissions
    run_laravel_commands
    restart_services
    health_check
    disable_maintenance
    cleanup
    
    log "=== Деплой завершен успешно! ==="
}

# Запуск скрипта
if [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
    echo "Использование: $0 [version]"
    echo "Автоматический деплой Laravel приложения Medical Center"
    echo ""
    echo "Опции:"
    echo "  --help, -h    Показать эту справку"
    echo "  --rollback    Откатиться к предыдущей версии"
    echo ""
    exit 0
fi

if [ "$1" = "--rollback" ]; then
    rollback
    exit 0
fi

# Проверяем что запускаем под правильным пользователем (root)
if [ "$EUID" -ne 0 ]; then
    error "Скрипт должен запускаться с правами root"
fi

main "$@"
