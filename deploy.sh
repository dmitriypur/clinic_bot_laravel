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
        php artisan down --message="Обновление системы" --retry=60
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

# Загрузка нового кода
download_code() {
    log "Загрузка нового кода..."
    
    # Создаем временную директорию
    mkdir -p "$TEMP_DIR"
    cd "$TEMP_DIR"
    
    # Клонируем репозиторий
    git clone --branch "$BRANCH" --single-branch "$REPO_URL" .
    
    # Удаляем .git директорию
    rm -rf .git
    
    log "Код загружен в $TEMP_DIR"
}

# Установка зависимостей
install_dependencies() {
    log "Установка зависимостей..."
    
    cd "$TEMP_DIR"
    
    # Composer зависимости
    composer install --no-dev --optimize-autoloader --no-interaction
    
    # NPM зависимости и сборка
    npm ci
    npm run build
    
    log "Зависимости установлены"
}

# Копирование конфигурации
copy_config() {
    log "Копирование конфигурации..."
    
    if [ -f "$APP_DIR/.env" ]; then
        cp "$APP_DIR/.env" "$TEMP_DIR/.env"
        log ".env файл скопирован"
    else
        error ".env файл не найден в текущей версии"
    fi
    
    # Копируем storage (логи, сессии, кеш)
    if [ -d "$APP_DIR/storage" ]; then
        cp -r "$APP_DIR/storage"/* "$TEMP_DIR/storage/"
        log "Директория storage скопирована"
    fi
}

# Настройка прав доступа
set_permissions() {
    log "Настройка прав доступа..."
    
    # Владелец и группа
    chown -R www-data:www-data "$TEMP_DIR"
    
    # Права на файлы и директории
    find "$TEMP_DIR" -type f -exec chmod 644 {} \;
    find "$TEMP_DIR" -type d -exec chmod 755 {} \;
    
    # Специальные права для storage и cache
    chmod -R 775 "$TEMP_DIR/storage"
    chmod -R 775 "$TEMP_DIR/bootstrap/cache"
    
    log "Права доступа настроены"
}

# Атомарная замена
atomic_deploy() {
    log "Атомарная замена приложения..."
    
    # Создаем временную директорию для старой версии
    OLD_DIR="/tmp/old-medical-center-$(date +%s)"
    
    # Атомарно перемещаем директории
    if [ -d "$APP_DIR" ]; then
        mv "$APP_DIR" "$OLD_DIR"
    fi
    
    mv "$TEMP_DIR" "$APP_DIR"
    
    # Удаляем старую версию после успешного деплоя
    if [ -d "$OLD_DIR" ]; then
        rm -rf "$OLD_DIR"
    fi
    
    log "Приложение заменено"
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
    systemctl reload php8.2-fpm
    
    # Перезапуск Supervisor воркеров
    supervisorctl restart laravel-worker:*
    
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
    download_code
    install_dependencies
    copy_config
    set_permissions
    atomic_deploy
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
