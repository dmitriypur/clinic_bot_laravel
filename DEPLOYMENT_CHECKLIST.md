# 🚀 Чек-лист для деплоя Medical Center Laravel

## ✅ Критические исправления выполнены

### 1. Миграции
- [x] **ИСПРАВЛЕНО**: `create_applications_table.php` - добавлен `AUTO_INCREMENT` для MySQL
- [x] Проверены все остальные миграции на корректность

### 2. Конфигурация
- [x] **ИСПРАВЛЕНО**: `.env.example` - добавлены настройки Telegram бота
- [x] Проверен `config/app.php` - часовой пояс настроен
- [x] Проверен `config/botman.php` - корректная конфигурация
- [x] Проверены API маршруты

### 3. Сидеры
- [x] **ИСПРАВЛЕНО**: `DatabaseSeeder.php` - добавлено создание админ-пользователя
- [x] Проверен `ShieldSeeder.php` - роли создаются корректно

### 4. Скрипт деплоя
- [x] **ИСПРАВЛЕНО**: `deploy.sh` - исправлена версия PHP (8.2/8.3)
- [x] **ИСПРАВЛЕНО**: `deploy.sh` - исправлены права доступа (laravel:www-data)

### 5. Зависимости
- [x] Проверен `composer.json` - валидный
- [x] Проверен `package.json` - уязвимостей нет
- [x] Проверен `.gitignore` - корректный

---

## 🔧 Что нужно сделать перед деплоем

### 1. Обязательные настройки на сервере

#### Системные требования
- [ ] Ubuntu 22.04 LTS или новее
- [ ] PHP 8.2+ с расширениями: mysql, redis, mbstring, xml, bcmath, curl, gd, zip, intl
- [ ] MySQL 8.0+ или PostgreSQL
- [ ] Redis Server
- [ ] Nginx или Apache
- [ ] Composer 2.0+
- [ ] Node.js 18+ и npm

#### Создание пользователя
```bash
# Создание пользователя laravel
adduser --disabled-password --gecos "" laravel
usermod -aG sudo laravel

# Настройка SSH ключей
mkdir -p /home/laravel/.ssh
cp /root/.ssh/authorized_keys /home/laravel/.ssh/
chown -R laravel:laravel /home/laravel/.ssh
chmod 700 /home/laravel/.ssh
chmod 600 /home/laravel/.ssh/authorized_keys
```

#### База данных
```bash
# Создание БД и пользователя
mysql -u root -p
CREATE DATABASE medical_center_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'laravel_user'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT ALL PRIVILEGES ON medical_center_laravel.* TO 'laravel_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 2. Настройка .env файла

**КРИТИЧЕСКИ ВАЖНО**: Скопировать `.env.example` и настроить:

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

# Telegram Bot (ОБЯЗАТЕЛЬНО!)
TELEGRAM_TOKEN=your_telegram_bot_token_from_botfather
TELEGRAM_BOT_USERNAME=your_bot_username
TELEGRAM_WEBHOOK_URL=https://your-domain.com/botman
```

### 3. Выполнение команд деплоя

```bash
# Переход в директорию приложения
cd /var/www/medical-center

# Установка зависимостей
composer install --no-dev --optimize-autoloader
npm ci --legacy-peer-deps
npm run build

# Настройка Laravel
php artisan key:generate
php artisan migrate --force
php artisan db:seed
php artisan storage:link

# Кеширование
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Настройка прав доступа
chown -R laravel:www-data /var/www/medical-center
chmod -R 755 /var/www/medical-center
chmod -R 775 /var/www/medical-center/storage
chmod -R 775 /var/www/medical-center/bootstrap/cache
```

### 4. Настройка Telegram бота

```bash
# Проверка информации о боте
php artisan telegram:info

# Установка webhook
php artisan telegram:webhook https://your-domain.com/botman

# Тестирование бота
# Отправьте /start боту в Telegram
```

### 5. Настройка Supervisor (для очередей)

```bash
# Создание конфигурации
sudo nano /etc/supervisor/conf.d/laravel-worker.conf
```

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

```bash
# Перезапуск Supervisor
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start laravel-worker:*
```

### 6. Настройка Cron (для планировщика)

```bash
# Добавление в crontab
sudo crontab -u laravel -e

# Добавить строку:
* * * * * cd /var/www/medical-center && php artisan schedule:run >> /dev/null 2>&1
```

---

## 🚨 Критические моменты

### 1. AUTO_INCREMENT проблема
**РЕШЕНО**: Миграция `create_applications_table.php` исправлена. На новом сервере проблема не возникнет.

### 2. Telegram бот
**ВАЖНО**: Обязательно настроить переменные окружения:
- `TELEGRAM_TOKEN`
- `TELEGRAM_BOT_USERNAME` 
- `TELEGRAM_WEBHOOK_URL`

### 3. Права доступа
**ВАЖНО**: Использовать пользователя `laravel`, а не `www-data`:
```bash
chown -R laravel:www-data /var/www/medical-center
```

### 4. База данных
**ВАЖНО**: Использовать MySQL с правильной кодировкой:
```sql
CREATE DATABASE medical_center_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

### 5. Кеширование
**ВАЖНО**: После деплоя обязательно выполнить:
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

---

## 🔍 Проверка после деплоя

### 1. Веб-интерфейс
- [ ] Главная страница: `https://your-domain.com`
- [ ] Админ-панель: `https://your-domain.com/admin`
  - Email: `admin@admin.ru`
  - Пароль: `password`

### 2. API
- [ ] Список городов: `https://your-domain.com/api/v1/cities`
- [ ] Список клиник: `https://your-domain.com/api/v1/clinics`

### 3. Telegram бот
- [ ] Отправить `/start` боту
- [ ] Проверить ответ бота
- [ ] Проверить webhook: `https://your-domain.com/botman`

### 4. Логи
```bash
# Проверка логов Laravel
tail -f /var/www/medical-center/storage/logs/laravel.log

# Проверка логов Nginx
tail -f /var/log/nginx/medical-center_error.log

# Проверка логов Supervisor
tail -f /var/www/medical-center/storage/logs/worker.log
```

---

## 🛠️ Команды для обслуживания

### Обновление приложения
```bash
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
```

### Очистка кешей
```bash
php artisan cache:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
```

### Перезапуск сервисов
```bash
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm  # или php8.3-fpm
sudo systemctl restart mysql
sudo systemctl restart redis-server
sudo supervisorctl restart all
```

---

## ✅ Финальный чек-лист

- [ ] Все критические исправления применены
- [ ] Сервер настроен согласно требованиям
- [ ] База данных создана и настроена
- [ ] .env файл настроен корректно
- [ ] Telegram бот настроен и работает
- [ ] Права доступа настроены правильно
- [ ] Supervisor настроен для очередей
- [ ] Cron настроен для планировщика
- [ ] Все сервисы запущены и работают
- [ ] Веб-интерфейс доступен
- [ ] API работает
- [ ] Telegram бот отвечает
- [ ] Логи не содержат критических ошибок

**Приложение готово к продуктивному использованию! 🎉**
