# Шпаргалка разработчика Telegram бота

## Команды для быстрого старта

```bash
# Полная настройка для разработки
php artisan bot:start-testing

# Проверка статуса бота
php artisan telegram:info

# Установка webhook вручную
php artisan telegram:webhook https://your-domain.com/botman

# Просмотр логов в реальном времени
tail -f storage/logs/laravel.log
```

## Структура файлов (только самое важное)

```
app/Bot/Conversations/
├── ApplicationConversation.php  # Основной диалог записи
└── ReviewConversation.php      # Диалог отзывов

app/Http/Controllers/Bot/
└── BotController.php           # Webhook контроллер

config/botman.php               # Конфигурация бота
routes/web.php                  # Маршрут /botman
```

## Основные классы и методы

### BotController
- `handle()` - обработка webhook
- `setupBotHandlers()` - регистрация команд

### ApplicationConversation
- `run()` - точка входа
- `showMainMenu()` - главное меню
- `askCity()` - выбор города
- `showDoctors()` - список врачей
- `createApplication()` - создание заявки

### ReviewConversation
- `run()` - точка входа
- `showDoctorAndAskRating()` - выбор оценки
- `askReviewText()` - текст отзыва
- `saveReview()` - сохранение

## Deep Links

Формат: `/start review_{doctor_uuid}`

Пример: `https://t.me/your_bot?start=review_123e4567-e89b-12d3-a456-426614174000`

## Файловое хранилище

```
storage/botman/
├── cache/                           # Кеш BotMan
├── conversation-{hash}.json         # Состояния диалогов  
└── user_Telegram_{user_id}.json     # Данные пользователей
```

## Отладка

### Проверка webhook
```bash
curl -X POST https://your-domain.com/botman \
  -H "Content-Type: application/json" \
  -d '{"message":{"text":"/start","from":{"id":123}}}'
```

### Очистка состояний
```bash
rm storage/botman/*.json
```

### Проверка логов
```bash
# Все логи
tail -f storage/logs/laravel.log

# Только ошибки бота
tail -f storage/logs/laravel.log | grep "Bot"
```

## Переменные окружения

```env
TELEGRAM_TOKEN=your_bot_token
APP_URL=https://your-domain.com
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database.sqlite
```

## Быстрые исправления

### Бот не отвечает
1. `php artisan telegram:info` - проверить webhook
2. `tail -f storage/logs/laravel.log` - смотреть ошибки
3. Проверить токен в `.env`

### Диалог завис
1. `rm storage/botman/*.json` - очистить состояния
2. `/start` в боте - перезапустить

### Webhook недоступен
1. Проверить доступность URL снаружи
2. Перенастроить: `php artisan telegram:webhook URL`

## Полезные команды Laravel

```bash
# Миграции
php artisan migrate:fresh --seed

# Очистка кеша
php artisan cache:clear
php artisan config:clear

# Просмотр маршрутов
php artisan route:list | grep botman

# Генерация ключа
php artisan key:generate
```

## Тестовые данные

После `php artisan db:seed` будут созданы:
- Города: Москва, СПб, Казань
- Клиники и врачи в каждом городе
- Тестовые отзывы

## Модели данных

### Основные связи
- City ↔ Clinic (многие ко многим)
- Clinic ↔ Doctor (многие ко многим)  
- Doctor → Review (один ко многим)
- Application (заявки на прием)

### Важные поля
- Doctor: `uuid` (для deep links), `rating`, `sum_ratings`, `count_ratings`
- Application: `tg_user_id`, `tg_chat_id`, `promo_code`
- Review: `rating`, `text`, `status`

## Безопасность

- CSRF исключен для `/botman`
- Валидация данных в диалогах
- Логирование всех ошибок
- Webhook всегда возвращает 200 OK

## Производительность

- Используется файловое хранилище (подходит до ~1000 пользователей)
- Кеширование конфигурации BotMan
- Ограничения Telegram: max 10 кнопок, 4000 символов текста
