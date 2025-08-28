# 🚀 Полная инструкция деплоя Laravel приложения с Telegram ботом

## 📋 Обзор

Это руководство поможет развернуть медицинский центр с Telegram ботом на Ubuntu сервере с нуля.

**Что будет установлено:**
- ✅ Nginx (веб-сервер)
- ✅ PHP 8.2 + расширения
- ✅ MySQL 8.0 (база данных)
- ✅ Redis (кеш и очереди)
- ✅ Supervisor (управление процессами)
- ✅ SSL сертификат (Let's Encrypt)
- ✅ Laravel приложение + Telegram бот

## 🖥️ Требования к серверу

- **ОС:** Ubuntu 22.04 LTS или новее
- **RAM:** Минимум 2GB, рекомендуется 4GB
- **Диск:** Минимум 20GB SSD
- **Домен:** Обязательно для SSL и webhook

---

## 🛠️ Этап 1: Подготовка сервера

### 1.1 Обновление системы

```bash
# Подключение к серверу
ssh root@your-server-ip

# Обновление пакетов
apt update && apt upgrade -y

# Установка базовых утилит
apt install -y curl wget git unzip software-properties-common apt-transport-https ca-certificates gnupg lsb-release
```

### 1.2 Создание пользователя для приложения

```bash
# Создание пользователя
adduser --disabled-password --gecos "" laravel

# Добавление в группу sudo
usermod -aG sudo laravel

# Настройка SSH ключей (если нужно)
mkdir -p /home/laravel/.ssh
cp /root/.ssh/authorized_keys /home/laravel/.ssh/
chown -R laravel:laravel /home/laravel/.ssh
chmod 700 /home/laravel/.ssh
chmod 600 /home/laravel/.ssh/authorized_keys
```

---

## 🐘 Этап 2: Установка PHP 8.2

### 2.1 Добавление репозитория PHP

```bash
# Добавление PPA репозитория
add-apt-repository ppa:ondrej/php -y
apt update
```

### 2.2 Установка PHP и расширений

```bash
# Установка PHP 8.2 и всех необходимых расширений
apt install -y php8.2 php8.2-fpm php8.2-mysql php8.2-redis php8.2-mbstring \
    php8.2-xml php8.2-bcmath php8.2-curl php8.2-gd php8.2-zip php8.2-intl \
    php8.2-soap php8.2-readline php8.2-msgpack php8.2-igbinary php8.2-cli
```

### 2.3 Настройка PHP-FPM

```bash
# Редактирование конфигурации PHP-FPM
nano /etc/php/8.2/fpm/pool.d/www.conf
```

**Важные настройки в файле:**
```ini
; Изменить пользователя
user = laravel
group = laravel

; Настройки сокета
listen = /run/php/php8.2-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660

; Процессы
pm = dynamic
pm.max_children = 50
pm.start_servers = 5
pm.min_spare_servers = 5
pm.max_spare_servers = 35
```

```bash
# Настройка php.ini
nano /etc/php/8.2/fpm/php.ini
```

**Ключевые параметры:**
```ini
max_execution_time = 300
memory_limit = 512M
upload_max_filesize = 100M
post_max_size = 100M
max_input_vars = 3000
date.timezone = Europe/Moscow
```

```bash
# Перезапуск PHP-FPM
systemctl restart php8.2-fpm
systemctl enable php8.2-fpm
```

---

## 🗄️ Этап 3: Установка MySQL

### 3.1 Установка MySQL Server

```bash
# Установка MySQL
apt install -y mysql-server

# Запуск скрипта безопасности
mysql_secure_installation
```

### 3.2 Создание базы данных и пользователя

```bash
# Подключение к MySQL
mysql -u root -p

# Создание базы данных
CREATE DATABASE medical_center_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# Создание пользователя
CREATE USER 'laravel_user'@'localhost' IDENTIFIED BY 'strong_password_here';

# Выдача прав
GRANT ALL PRIVILEGES ON medical_center_laravel.* TO 'laravel_user'@'localhost';
FLUSH PRIVILEGES;

# Выход
EXIT;
```

---

## 🔴 Этап 4: Установка Redis

### 4.1 Установка Redis Server

```bash
# Установка Redis
apt install -y redis-server

# Настройка конфигурации
nano /etc/redis/redis.conf
```

**Важные настройки:**
```ini
# Привязка к localhost
bind 127.0.0.1 ::1

# Отключение защищенного режима для localhost
protected-mode yes

# Настройка памяти
maxmemory 256mb
maxmemory-policy allkeys-lru

# Сохранение данных
save 900 1
save 300 10
save 60 10000
```

```bash
# Перезапуск Redis
systemctl restart redis-server
systemctl enable redis-server

# Проверка работы
redis-cli ping
# Ответ: PONG
```

---

## 🌐 Этап 5: Установка Nginx

### 5.1 Установка Nginx

```bash
# Установка Nginx
apt install -y nginx

# Запуск и автозагрузка
systemctl start nginx
systemctl enable nginx
```

### 5.2 Настройка Nginx для Laravel

```bash
# Создание конфигурации сайта
nano /etc/nginx/sites-available/medical-center
```

**Конфигурация сайта:**
```nginx
server {
    listen 80;
    listen [::]:80;
    server_name your-domain.com www.your-domain.com;
    root /var/www/medical-center/public;
    index index.php index.html;

    # Максимальный размер загружаемых файлов
    client_max_body_size 100M;

    # Основное местоположение
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # PHP обработка
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        
        # Увеличиваем таймауты для долгих операций
        fastcgi_read_timeout 300;
        fastcgi_connect_timeout 300;
        fastcgi_send_timeout 300;
    }

    # Запрет доступа к скрытым файлам
    location ~ /\. {
        deny all;
    }

    # Запрет доступа к служебным файлам Laravel
    location ~ /(\.env|\.git|composer\.(json|lock)|package\.(json|lock)|webpack\.mix\.js|yarn\.lock) {
        deny all;
    }

    # Статические файлы
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # Логи
    access_log /var/log/nginx/medical-center_access.log;
    error_log /var/log/nginx/medical-center_error.log;
}
```

```bash
# Активация сайта
ln -s /etc/nginx/sites-available/medical-center /etc/nginx/sites-enabled/

# Удаление дефолтного сайта
rm -f /etc/nginx/sites-enabled/default

# Проверка конфигурации
nginx -t

# Перезагрузка Nginx
systemctl reload nginx
```

---

## 📦 Этап 6: Установка Composer

```bash
# Скачивание Composer
curl -sS https://getcomposer.org/installer | php

# Установка глобально
mv composer.phar /usr/local/bin/composer

# Проверка установки
composer --version
```

---

## 🚀 Этап 7: Деплой Laravel приложения

### 7.1 Клонирование репозитория

```bash
# Переключение на пользователя laravel
su - laravel

# Переход в директорию веб-сервера
sudo mkdir -p /var/www
sudo chown laravel:laravel /var/www
cd /var/www

# Клонирование репозитория (замените на ваш URL)
git clone https://github.com/your-username/adminzrenie-laravel.git medical-center
cd medical-center

# Установка прав доступа
sudo chown -R laravel:www-data /var/www/medical-center
sudo chmod -R 755 /var/www/medical-center
sudo chmod -R 775 /var/www/medical-center/storage
sudo chmod -R 775 /var/www/medical-center/bootstrap/cache
```

### 7.2 Установка зависимостей

```bash
# Установка PHP зависимостей
composer install --no-dev --optimize-autoloader

# Установка Node.js и npm
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# Установка JavaScript зависимостей
npm install

# Сборка фронтенда
npm run build
```

### 7.3 Настройка окружения

```bash
# Создание .env файла
cp .env.example .env
nano .env
```

**Конфигурация .env файла:**
```env
APP_NAME="Medical Center"
APP_ENV=production
APP_KEY=base64:generate_key_here
APP_DEBUG=false
APP_TIMEZONE="Europe/Moscow"
APP_URL=https://your-domain.com

# База данных
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=medical_center_laravel
DB_USERNAME=laravel_user
DB_PASSWORD=strong_password_here

# Кеш и сессии
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Telegram Bot
TELEGRAM_TOKEN=your_telegram_bot_token_from_botfather
TELEGRAM_BOT_USERNAME=your_bot_username
TELEGRAM_WEBHOOK_URL=https://your-domain.com/botman

# Почта (настройте по необходимости)
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=your-email@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=your-email@gmail.com
MAIL_FROM_NAME="${APP_NAME}"

# Логирование
LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=info
```

### 7.4 Настройка приложения

```bash
# Генерация ключа приложения
php artisan key:generate

# Кеширование конфигурации
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Выполнение миграций
php artisan migrate --force

# Запуск сидеров (заполнение тестовыми данными)
php artisan db:seed

# Создание символической ссылки для storage
php artisan storage:link

# Очистка кешей
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

---

## 🔒 Этап 8: Настройка SSL с Let's Encrypt

### 8.1 Установка Certbot

```bash
# Установка Certbot
sudo apt install -y certbot python3-certbot-nginx

# Получение SSL сертификата
sudo certbot --nginx -d your-domain.com -d www.your-domain.com
```

### 8.2 Автоматическое обновление сертификатов

```bash
# Проверка автообновления
sudo certbot renew --dry-run

# Добавление в cron (если не добавлен автоматически)
sudo crontab -e

# Добавить строку:
# 0 12 * * * /usr/bin/certbot renew --quiet
```

---

## 🎛️ Этап 9: Настройка Supervisor

### 9.1 Установка Supervisor

```bash
# Установка Supervisor
sudo apt install -y supervisor
```

### 9.2 Настройка Laravel Queue Worker

```bash
# Создание конфигурации для Laravel очередей
sudo nano /etc/supervisor/conf.d/laravel-worker.conf
```

**Конфигурация worker'а:**
```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/medical-center/artisan queue:work redis --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=laravel
numprocs=4
redirect_stderr=true
stdout_logfile=/var/www/medical-center/storage/logs/worker.log
stopwaitsecs=3600
```

### 9.3 Настройка Laravel Scheduler

```bash
# Добавление в crontab для пользователя laravel
sudo crontab -u laravel -e

# Добавить строку:
# * * * * * cd /var/www/medical-center && php artisan schedule:run >> /dev/null 2>&1
```

### 9.4 Запуск Supervisor

```bash
# Перечитывание конфигурации
sudo supervisorctl reread
sudo supervisorctl update

# Запуск worker'ов
sudo supervisorctl start laravel-worker:*

# Проверка статуса
sudo supervisorctl status
```

---

## 🤖 Этап 10: Настройка Telegram бота

### 10.1 Настройка webhook

```bash
# Переход в директорию приложения
cd /var/www/medical-center

# Проверка информации о боте
php artisan telegram:info

# Установка webhook
php artisan telegram:webhook https://app.fondzrenie.ru/botman
```

### 10.2 Проверка работы бота

```bash
# Проверка логов
tail -f storage/logs/laravel.log

# Тестирование бота
# Отправьте /start боту в Telegram
```

---

## 🔧 Этап 11: Настройка мониторинга и логов

### 11.1 Настройка ротации логов

```bash
# Создание конфигурации logrotate
sudo nano /etc/logrotate.d/laravel
```

```
/var/www/medical-center/storage/logs/*.log {
    daily
    missingok
    rotate 52
    compress
    delaycompress
    notifempty
    create 644 laravel laravel
}
```

### 11.2 Мониторинг системы

```bash
# Установка htop для мониторинга
sudo apt install -y htop

# Проверка использования ресурсов
htop

# Проверка дискового пространства
df -h

# Проверка памяти
free -h
```

---

## 🚀 Этап 12: Финальная проверка

### 12.1 Проверка сервисов

```bash
# Статус всех сервисов
sudo systemctl status nginx php8.2-fpm mysql redis-server supervisor

# Проверка портов
sudo netstat -tulpn | grep -E ':80|:443|:3306|:6379'
```

### 12.2 Проверка приложения

```bash
# Проверка прав доступа
ls -la /var/www/medical-center/storage/
ls -la /var/www/medical-center/bootstrap/cache/

# Проверка конфигурации Laravel
cd /var/www/medical-center
php artisan about

# Проверка очередей
php artisan queue:work --once

# Проверка подключения к базе данных
php artisan tinker
# В tinker: \App\Models\User::count()
```

### 12.3 Тестирование веб-интерфейса

1. **Главная страница:** `https://your-domain.com`
2. **Админ-панель:** `https://your-domain.com/admin`
   - Email: admin@admin.ru
   - Пароль: password
3. **API:** `https://your-domain.com/api/v1/cities`
4. **Telegram бот:** Отправить `/start` боту

---

## 🛡️ Этап 13: Безопасность

### 13.1 Настройка файрвола

```bash
# Установка ufw
sudo apt install -y ufw

# Базовые правила
sudo ufw default deny incoming
sudo ufw default allow outgoing

# Разрешение SSH
sudo ufw allow ssh

# Разрешение HTTP/HTTPS
sudo ufw allow 'Nginx Full'

# Включение файрвола
sudo ufw --force enable

# Проверка статуса
sudo ufw status
```

### 13.2 Настройка автоматических обновлений

```bash
# Установка unattended-upgrades
sudo apt install -y unattended-upgrades

# Настройка автообновлений
sudo dpkg-reconfigure -plow unattended-upgrades

# Выбрать "Yes" для автоматических обновлений безопасности
```

---

## 📋 Чек-лист деплоя

### ✅ Системные компоненты
- [ ] Ubuntu обновлен
- [ ] Пользователь `laravel` создан
- [ ] PHP 8.2 + расширения установлены
- [ ] MySQL настроен и БД создана
- [ ] Redis работает
- [ ] Nginx настроен
- [ ] SSL сертификат получен

### ✅ Laravel приложение
- [ ] Код склонирован
- [ ] Composer зависимости установлены
- [ ] .env файл настроен
- [ ] Ключ приложения сгенерирован
- [ ] Миграции выполнены
- [ ] Права доступа настроены
- [ ] Кеши созданы

### ✅ Фоновые процессы
- [ ] Supervisor установлен и настроен
- [ ] Queue worker'ы запущены
- [ ] Cron job для планировщика добавлен

### ✅ Telegram бот
- [ ] Токен добавлен в .env
- [ ] Webhook настроен
- [ ] Бот отвечает на команды

### ✅ Безопасность
- [ ] Файрвол настроен
- [ ] SSL работает
- [ ] Автообновления включены

---

## 🔧 Полезные команды для обслуживания

### Общие команды

```bash
# Обновление приложения
cd /var/www/medical-center
git pull
composer install --no-dev --optimize-autoloader
npm run build
php artisan migrate --force
php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo supervisorctl restart laravel-worker:*

# Проверка логов
tail -f storage/logs/laravel.log
tail -f /var/log/nginx/medical-center_error.log

# Очистка кешей
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear

# Перезапуск сервисов
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm
sudo systemctl restart mysql
sudo systemctl restart redis-server
sudo supervisorctl restart all
```

### Команды для Telegram бота

```bash
# Информация о боте
php artisan telegram:info

# Установка webhook
php artisan telegram:webhook https://your-domain.com/botman

# Тестирование бота
php artisan bot:test

# Просмотр логов бота
tail -f storage/logs/laravel.log | grep -i telegram
```

### Мониторинг

```bash
# Статус сервисов
sudo systemctl status nginx php8.2-fpm mysql redis-server supervisor

# Использование ресурсов
htop
df -h
free -h

# Активные подключения
sudo netstat -tulpn
sudo ss -tulpn

# Supervisor процессы
sudo supervisorctl status
```

---

## 🆘 Решение проблем

### Nginx не запускается
```bash
# Проверка конфигурации
sudo nginx -t

# Проверка логов
sudo tail -f /var/log/nginx/error.log

# Проверка занятых портов
sudo netstat -tulpn | grep :80
```

### PHP-FPM ошибки
```bash
# Проверка статуса
sudo systemctl status php8.2-fpm

# Проверка логов
sudo tail -f /var/log/php8.2-fpm.log

# Перезапуск
sudo systemctl restart php8.2-fpm
```

### База данных недоступна
```bash
# Проверка статуса MySQL
sudo systemctl status mysql

# Подключение к БД
mysql -u laravel_user -p medical_center_laravel

# Проверка логов
sudo tail -f /var/log/mysql/error.log
```

### Redis проблемы
```bash
# Проверка статуса
sudo systemctl status redis-server

# Тестирование подключения
redis-cli ping

# Проверка конфигурации
sudo nano /etc/redis/redis.conf
```

### Telegram бот не отвечает
```bash
# Проверка webhook
curl -X GET "https://api.telegram.org/bot{TELEGRAM_TOKEN}/getWebhookInfo"

# Проверка логов
tail -f storage/logs/laravel.log | grep -i telegram

# Тест webhook
curl -X POST https://your-domain.com/botman

# Переустановка webhook
php artisan telegram:webhook https://your-domain.com/botman
```

### Laravel ошибки 500
```bash
# Включение debug режима временно
nano .env
# APP_DEBUG=true

# Проверка логов
tail -f storage/logs/laravel.log

# Очистка кешей
php artisan cache:clear
php artisan config:clear

# Проверка прав доступа
sudo chown -R laravel:www-data /var/www/medical-center
sudo chmod -R 755 /var/www/medical-center
sudo chmod -R 775 /var/www/medical-center/storage
sudo chmod -R 775 /var/www/medical-center/bootstrap/cache
```

---

## 🎯 Заключение

После выполнения всех этапов у вас будет полностью функционирующее Laravel приложение с Telegram ботом:

- **Веб-интерфейс** доступен по адресу `https://your-domain.com`
- **Админ-панель** доступна по адресу `https://your-domain.com/admin`
- **Telegram бот** отвечает на команды и обрабатывает записи к врачу
- **API** работает для интеграций
- **Автоматические резервные копии** и мониторинг настроены

Приложение готово к продуктивному использованию! 🎉
