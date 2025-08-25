# КОНТЕКСТ ПРОЕКТА: Медицинский центр - Laravel + Telegram Bot

## 📋 ОБЩАЯ ИНФОРМАЦИЯ

**Задача:** Портирование Flask Python приложения на Laravel с сохранением всего функционала
**Статус:** ✅ Основной функционал реализован и работает
**Дата создания:** 20 августа 2025
**Laravel версия:** 12.25.0
**PHP версия:** 8.3.16

## 🏗️ АРХИТЕКТУРА ПРОЕКТА

### Основные сущности (из оригинального Flask):
- **User** - пользователи системы (админы, врачи)
- **Application** - заявки на запись к врачу
- **City** - города где есть клиники
- **Clinic** - медицинские клиники
- **Doctor** - врачи с рейтингами
- **Review** - отзывы о врачах (1-5 звезд)
- **Webhook** - настройки для интеграций

### Связи между сущностями:
- City ↔ Clinic (many-to-many)
- Clinic ↔ Doctor (many-to-many) 
- Doctor → Review (one-to-many)
- City → Application (one-to-many)
- Doctor → Application (one-to-many)

## ✅ ЧТО РЕАЛИЗОВАНО

### 1. База данных и модели
- ✅ Все миграции созданы и выполнены
- ✅ Eloquent модели с правильными связями
- ✅ Сидеры с тестовыми данными
- ✅ Индексы и внешние ключи

### 2. API (совместимо с оригинальным Flask)
- ✅ `GET /api/v1/cities/` - список городов
- ✅ `POST /api/v1/applications/create/` - создание заявки
- ✅ `GET /api/v1/webhook/` - CRUD операции с webhook
- ✅ Полный RESTful API для всех сущностей

### 3. Telegram Bot (BotMan)
- ✅ Команда `/start` с главным меню
- ✅ Диалог записи на прием (ApplicationConversation)
- ✅ Система отзывов (ReviewConversation) 
- ✅ Deep links для отзывов: `t.me/бот?start=review_uuid`
- ✅ Промо-коды в процессе записи
- ✅ Кнопка "Поделиться номером телефона" (request_contact)
- ✅ File storage для состояний (вместо Redis)
- ✅ Webhook настроен и работает

### 4. Админ-панель (Filament)
- ✅ Ресурсы для всех моделей
- ✅ CRUD операции через веб-интерфейс
- ✅ Доступ: http://127.0.0.1:8000/admin
- ✅ Логин: admin@admin.ru / password

### 5. Консольные команды
- ✅ `php artisan telegram:info` - информация о боте
- ✅ `php artisan telegram:webhook <url>` - настройка webhook
- ✅ `php artisan bot:start-testing` - автонастройка для тестирования
- ✅ `php artisan bot:test` - тестирование компонентов

## 🔧 ТЕКУЩИЕ НАСТРОЙКИ

### Environment (.env):
```
TELEGRAM_TOKEN=8339267972:AAFTDl565kF2mH-vEqdOWh-hf7KPqjgva28
TELEGRAM_WEBHOOK_URL=https://33963c6eaa18.ngrok-free.app/botman
```

### BotMan конфигурация:
- Драйвер: Telegram
- Storage: File (storage/botman/)
- Cache: File (storage/botman/cache/)

### CSRF защита:
- Исключения: `botman`, `api/*`
- Настроено в `bootstrap/app.php`

## 🎯 ФУНКЦИОНАЛ БОТА

### Главное меню (/start):
1. 📝 Записаться на прием
2. 👩🏻‍⚕️ Просмотр врачей
3. 🎁 Запись с промокодом
4. 👉 Телеграм канал

### Диалог записи:
1. Ввод даты рождения (опционально)
2. Выбор города
3. Выбор клиники или врача
4. Просмотр информации о враче
5. Ввод телефона
6. Ввод ФИО пациента
7. Ввод ФИО родителя (опционально)
8. Согласие на обработку данных
9. Создание заявки в БД

### Система отзывов:
- Deep link: `t.me/medical_center_test_bot?start=review_{doctor_uuid}`
- Оценка 1-5 звезд
- Текстовый отзыв (опционально)
- Автоматическое обновление рейтинга врача

## 🐛 РЕШЕННЫЕ ПРОБЛЕМЫ

1. **CSRF ошибка 419** → добавили исключения для webhook
2. **Redis недоступен** → переключились на file storage
3. **BotMan не отвечал** → исправили конфигурацию storage
4. **API 404 ошибки** → создали правильные роуты в стиле Flask

## 🚀 ТЕСТИРОВАНИЕ

### Локальное тестирование с ngrok:
1. `ngrok http 8000` (в отдельном терминале)
2. `php artisan bot:start-testing`
3. Бот: https://t.me/medical_center_test_bot

### Проверка статуса:
- `php artisan telegram:info`
- `tail -f storage/logs/laravel.log`

## 📁 КЛЮЧЕВЫЕ ФАЙЛЫ

### Модели:
- `app/Models/User.php`
- `app/Models/Application.php`
- `app/Models/City.php`
- `app/Models/Clinic.php`
- `app/Models/Doctor.php`
- `app/Models/Review.php`
- `app/Models/Webhook.php`

### Бот:
- `app/Http/Controllers/Bot/BotController.php`
- `app/Bot/Conversations/ApplicationConversation.php`
- `app/Bot/Conversations/ReviewConversation.php`
- `config/botman.php`

### API:
- `routes/api.php`
- `app/Http/Controllers/Api/`
- `app/Http/Resources/`

### Миграции:
- `database/migrations/` (все файлы)
- `database/seeders/`

## 🎯 СЛЕДУЮЩИЕ ЭТАПЫ

### Можно добавить:
1. **Интеграция с 1C** через Laravel Queues
2. **Уведомления** через webhook API
3. **Файлы** (фото врачей, дипломы)
4. **Тесты** (Unit, Feature)
5. **Docker** для деплоя
6. **Кеширование** (Redis в продакшене)

### Промо-коды (уже работают):
- Валидация кодов
- Применение скидок
- Статистика использования

## 🔗 ПОЛЕЗНЫЕ ССЫЛКИ

- **Бот:** https://t.me/medical_center_test_bot
- **Админка:** http://127.0.0.1:8000/admin
- **API:** http://127.0.0.1:8000/api/v1/
- **Laravel Docs:** https://laravel.com/docs
- **BotMan Docs:** https://botman.io/

---

**💡 ВАЖНО:** Этот документ содержит полный контекст проекта. При работе в новой сессии Cursor просто ссылайтесь на него для восстановления контекста.



