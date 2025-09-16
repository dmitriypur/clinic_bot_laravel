# 🚀 Настройка очередей на удаленном сервере

## 🎯 Обзор

На удаленном сервере очереди должны работать постоянно в фоне. Вот несколько способов настройки:

## 1. 🔧 Supervisor (Рекомендуется)

### Установка Supervisor
```bash
# Ubuntu/Debian
sudo apt update
sudo apt install supervisor

# CentOS/RHEL
sudo yum install supervisor
```

### Создание конфигурации
Создайте файл `/etc/supervisor/conf.d/laravel-worker.conf`:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/project/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/your/project/storage/logs/worker.log
stopwaitsecs=3600
```

### Запуск
```bash
# Перезагрузить конфигурацию
sudo supervisorctl reread
sudo supervisorctl update

# Запустить воркеры
sudo supervisorctl start laravel-worker:*

# Проверить статус
sudo supervisorctl status
```

## 2. 🐳 Docker

### Dockerfile для воркера
```dockerfile
FROM php:8.3-fpm

# Установка зависимостей
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip

# Установка PHP расширений
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Установка Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Рабочая директория
WORKDIR /var/www

# Копирование проекта
COPY . .

# Установка зависимостей
RUN composer install --no-dev --optimize-autoloader

# Права доступа
RUN chown -R www-data:www-data /var/www
RUN chmod -R 755 /var/www/storage

# Команда для воркера
CMD ["php", "artisan", "queue:work", "--verbose", "--tries=3", "--timeout=90"]
```

### docker-compose.yml
```yaml
version: '3.8'

services:
  app:
    build: .
    ports:
      - "8000:8000"
    volumes:
      - .:/var/www
    environment:
      - QUEUE_CONNECTION=database

  queue-worker:
    build: .
    command: php artisan queue:work --verbose --tries=3 --timeout=90
    volumes:
      - .:/var/www
    environment:
      - QUEUE_CONNECTION=database
    depends_on:
      - app
    restart: unless-stopped
```

## 3. 🔄 Systemd (Linux)

### Создание сервиса
Создайте файл `/etc/systemd/system/laravel-worker.service`:

```ini
[Unit]
Description=Laravel Queue Worker
After=network.target

[Service]
User=www-data
Group=www-data
Restart=always
ExecStart=/usr/bin/php /path/to/your/project/artisan queue:work --sleep=3 --tries=3 --max-time=3600
WorkingDirectory=/path/to/your/project

[Install]
WantedBy=multi-user.target
```

### Запуск
```bash
# Перезагрузить systemd
sudo systemctl daemon-reload

# Включить автозапуск
sudo systemctl enable laravel-worker

# Запустить сервис
sudo systemctl start laravel-worker

# Проверить статус
sudo systemctl status laravel-worker
```

## 4. ☁️ Облачные решения

### Laravel Forge
1. Подключите сервер к Forge
2. В разделе "Queue" добавьте воркер
3. Forge автоматически настроит Supervisor

### Laravel Vapor (AWS)
```php
// vapor.yml
id: your-app-id
name: your-app-name
environments:
  production:
    queue: your-queue-name
    workers: 2
```

### Heroku
```bash
# Добавить в Procfile
worker: php artisan queue:work --verbose --tries=3 --timeout=90

# Масштабировать воркеры
heroku ps:scale worker=2
```

## 5. 🛠️ Ручной запуск (временное решение)

### Screen/Tmux
```bash
# Создать сессию screen
screen -S laravel-queue

# Запустить воркер
php artisan queue:work --verbose --tries=3 --timeout=90

# Отключиться (Ctrl+A, затем D)
# Подключиться обратно: screen -r laravel-queue
```

### Nohup
```bash
# Запуск в фоне
nohup php artisan queue:work --verbose --tries=3 --timeout=90 > storage/logs/queue.log 2>&1 &

# Проверить процесс
ps aux | grep "queue:work"
```

## 6. 📊 Мониторинг очередей

### Laravel Horizon (Redis)
```bash
# Установка
composer require laravel/horizon

# Публикация конфигурации
php artisan horizon:install

# Запуск
php artisan horizon
```

### Мониторинг через команды
```bash
# Проверить количество задач
php artisan queue:monitor

# Статистика очередей
php artisan queue:stats

# Очистить неудачные задачи
php artisan queue:flush
```

## 7. 🔧 Настройка для продакшена

### Оптимизация .env
```env
# Продакшен настройки
QUEUE_CONNECTION=database
DB_QUEUE_CONNECTION=mysql
DB_QUEUE_TABLE=jobs
DB_QUEUE_RETRY_AFTER=90

# Или для Redis
QUEUE_CONNECTION=redis
REDIS_QUEUE_CONNECTION=default
REDIS_QUEUE=default
REDIS_QUEUE_RETRY_AFTER=90
```

### Настройка базы данных
```sql
-- Создать индексы для таблицы jobs
CREATE INDEX idx_jobs_queue ON jobs(queue);
CREATE INDEX idx_jobs_available_at ON jobs(available_at);
CREATE INDEX idx_jobs_reserved_at ON jobs(reserved_at);
```

### Логирование
```php
// config/logging.php
'channels' => [
    'queue' => [
        'driver' => 'daily',
        'path' => storage_path('logs/queue.log'),
        'level' => 'info',
        'days' => 14,
    ],
],
```

## 8. 🚨 Устранение проблем

### Воркер не запускается
```bash
# Проверить права доступа
sudo chown -R www-data:www-data /path/to/project
sudo chmod -R 755 /path/to/project/storage

# Проверить логи
tail -f storage/logs/laravel.log
tail -f storage/logs/queue.log
```

### Задачи накапливаются
```bash
# Увеличить количество воркеров
sudo supervisorctl start laravel-worker:laravel-worker_02

# Или изменить numprocs в конфигурации Supervisor
```

### Память/CPU проблемы
```bash
# Ограничить время работы воркера
php artisan queue:work --max-time=3600

# Ограничить количество задач
php artisan queue:work --max-jobs=1000
```

## 9. 📋 Чек-лист развертывания

- [ ] Установлен Supervisor/Systemd
- [ ] Настроена конфигурация воркера
- [ ] Проверены права доступа к папкам
- [ ] Настроено логирование
- [ ] Созданы индексы в БД
- [ ] Настроен мониторинг
- [ ] Протестирован экспорт
- [ ] Настроен автозапуск при перезагрузке

## 🎉 Готово!

Теперь очереди будут работать стабильно на удаленном сервере!
