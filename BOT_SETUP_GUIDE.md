# Руководство по настройке и запуску Telegram бота

## Быстрый старт

### 1. Создание бота в Telegram

1. Найдите @BotFather в Telegram
2. Отправьте `/newbot`
3. Введите название бота (например: "Медицинский центр")
4. Введите username бота (например: "medical_center_test_bot")
5. Сохраните полученный токен в формате: `1234567890:ABCdefGHIjklMNOpqrsTUVwxyz`

### 2. Настройка проекта

#### Установка зависимостей
```bash
composer install
npm install
```

#### Настройка базы данных
```bash
# Создание БД (SQLite по умолчанию)
touch database/database.sqlite

# Выполнение миграций
php artisan migrate

# Заполнение тестовыми данными
php artisan db:seed
```

#### Конфигурация окружения
Создайте `.env` файл на основе `.env.example`:
```bash
cp .env.example .env
php artisan key:generate
```

Добавьте токен бота в `.env`:
```env
TELEGRAM_TOKEN=ваш_токен_от_botfather
```

### 3. Локальная разработка с ngrok

#### Установка ngrok
```bash
# MacOS
brew install ngrok

# Другие системы - скачайте с https://ngrok.com/
```

#### Запуск туннеля
```bash
# Запустите Laravel сервер
php artisan serve

# В другом терминале запустите ngrok
ngrok http 8000
```

#### Настройка webhook
```bash
# Используйте HTTPS URL от ngrok
php artisan telegram:webhook https://your-ngrok-url.ngrok.io/botman
```

### 4. Автоматическая настройка для тестирования

Вместо ручной настройки можно использовать команду:
```bash
php artisan bot:start-testing
```

Эта команда автоматически:
- Запускает ngrok туннель
- Настраивает webhook
- Показывает информацию о боте

## Структура файлов бота

### Основные файлы
```
app/Bot/Conversations/
├── ApplicationConversation.php  # Диалог записи на прием
└── ReviewConversation.php      # Диалог оставления отзывов

app/Http/Controllers/Bot/
└── BotController.php           # Основной контроллер бота

config/botman.php               # Конфигурация BotMan
routes/web.php                  # Webhook маршрут
storage/botman/                 # Файловое хранилище состояний
```

### Конфигурационные файлы
- `.env` - переменные окружения (токен бота)
- `config/botman.php` - настройки BotMan framework
- `routes/web.php` - маршрут для webhook `/botman`

## Команды для управления ботом

### Информация о боте
```bash
php artisan telegram:info
```
Показывает:
- Информацию о боте
- Статус webhook
- Последние обновления

### Установка webhook
```bash
php artisan telegram:webhook https://your-domain.com/botman
```

### Удаление webhook
```bash
php artisan telegram:webhook delete
```

### Автонастройка для разработки
```bash
php artisan bot:start-testing
```

### Тестирование диалогов
```bash
php artisan bot:test-locally
```

## Как работает бот

### Обработка сообщений

1. **Webhook получает сообщение** от Telegram API
2. **BotController** обрабатывает HTTP запрос
3. **BotMan** определяет команду или текст сообщения
4. **Запускается соответствующий обработчик**:
   - `/start` → ApplicationConversation
   - `/start review_uuid` → ReviewConversation
   - Остальное → fallback

### Состояния диалогов

Состояния сохраняются в файлах:
```
storage/botman/
├── cache/                           # Временный кеш
├── conversation-{hash}.json         # Состояния диалогов
└── user_Telegram_{user_id}.json     # Данные пользователей
```

### Процесс записи на прием

```mermaid
graph TD
    A[/start] --> B[Главное меню]
    B --> C{Выбор действия}
    C -->|Записаться| D[Дата рождения]
    C -->|Промокод| E[Ввод промокода]
    C -->|Просмотр| F[Выбор города]
    D --> F
    E --> F
    F --> G[Клиники или врачи]
    G --> H[Выбор врача]
    H --> I[Информация о враче]
    I --> J[Запись: телефон]
    J --> K[ФИО пациента]
    K --> L[ФИО родителя]
    L --> M[Согласие на обработку]
    M --> N[Создание заявки]
```

### Процесс оставления отзыва

```mermaid
graph TD
    A[/start review_uuid] --> B[Проверка врача]
    B --> C[Информация о враче]
    C --> D[Выбор оценки 1-5]
    D --> E[Текст отзыва]
    E --> F[Подтверждение]
    F --> G[Сохранение в БД]
    G --> H[Обновление рейтинга]
```

## Тестирование бота

### 1. Проверка webhook
```bash
# Проверьте статус
php artisan telegram:info

# Должно показать:
# Webhook URL: https://your-domain.com/botman
# Webhook Status: OK
```

### 2. Тестирование команд

Откройте бота в Telegram и попробуйте:
- `/start` - главное меню
- Запись на прием
- Просмотр врачей
- Оставление отзыва (через deep link)

### 3. Проверка логов

При ошибках смотрите логи:
```bash
tail -f storage/logs/laravel.log
```

### 4. Проверка состояний

Файлы состояний создаются при активных диалогах:
```bash
ls -la storage/botman/
```

## Развертывание на продакшене

### 1. Подготовка сервера

```bash
# Установка зависимостей
composer install --no-dev --optimize-autoloader

# Кеширование конфигурации
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Установка прав на папки
chmod -R 775 storage bootstrap/cache
```

### 2. Настройка веб-сервера

Nginx конфигурация:
```nginx
server {
    listen 80;
    server_name your-domain.com;
    root /path/to/project/public;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 3. Настройка HTTPS

Обязательно используйте HTTPS для webhook:
```bash
# Установка сертификата Let's Encrypt
certbot --nginx -d your-domain.com
```

### 4. Установка webhook на продакшене

```bash
php artisan telegram:webhook https://your-domain.com/botman
```

## Мониторинг и обслуживание

### Логирование

Все действия бота логируются в:
```
storage/logs/laravel.log
```

Критичные события:
- Ошибки webhook
- Ошибки создания заявок
- Ошибки сохранения отзывов

### Очистка состояний

Периодически очищайте старые состояния:
```bash
# Удаление файлов старше 24 часов
find storage/botman/ -name "*.json" -mtime +1 -delete
```

### Резервное копирование

Регулярно создавайте бэкапы:
```bash
# База данных
cp database/database.sqlite backups/db_$(date +%Y%m%d).sqlite

# Состояния бота
tar -czf backups/botman_$(date +%Y%m%d).tar.gz storage/botman/
```

### Мониторинг webhook

Настройте мониторинг доступности webhook:
```bash
# Проверка статуса
curl -I https://your-domain.com/botman
```

## Часто встречающиеся проблемы

### Бот не отвечает
1. Проверьте токен в `.env`
2. Проверьте статус webhook: `php artisan telegram:info`
3. Проверьте логи: `tail -f storage/logs/laravel.log`
4. Проверьте доступность домена извне

### Диалог "зависает"
1. Очистите состояния: `rm storage/botman/*.json`
2. Перезапустите диалог: `/start`

### Ошибки в логах
1. Проверьте права на папки: `chmod -R 775 storage`
2. Проверьте подключение к БД
3. Проверьте корректность конфигурации

### Ngrok туннель отключается
1. Используйте платную версию для стабильности
2. Или настройте автоматический перезапуск
3. Перенастройте webhook при смене URL

## Кастомизация

### Изменение текстов

Тексты бота находятся в файлах:
- `app/Bot/Conversations/ApplicationConversation.php`
- `app/Bot/Conversations/ReviewConversation.php`

### Добавление новых команд

1. Добавьте обработчик в `BotController::setupBotHandlers()`
2. Создайте новый Conversation класс при необходимости

### Изменение логики

Основная логика находится в методах Conversation классов.
Каждый метод отвечает за один шаг диалога.

## Интеграция с внешними системами

### Отправка в 1C

В `ApplicationConversation::createApplication()` добавьте:
```php
// TODO: Интеграция с 1C через API или очередь
dispatch(new SendTo1CJob($application));
```

### Уведомления

Добавьте отправку уведомлений:
```php
// Email уведомления
Mail::to('admin@clinic.com')->send(new NewApplicationMail($application));

// SMS уведомления
// Используйте подходящий SMS провайдер
```

### Аналитика

Добавьте отслеживание событий:
```php
// Google Analytics или другие системы
Analytics::track('application_created', [
    'doctor_id' => $application->doctor_id,
    'city_id' => $application->city_id,
]);
```
