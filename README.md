# Медицинский центр - Laravel + Telegram Bot

Портирование Flask Python приложения на Laravel с полным сохранением функционала.

## 🚀 Быстрый старт

### Требования
- PHP 8.3+
- Composer
- SQLite
- Ngrok (для тестирования бота)

### Установка
```bash
# Установка зависимостей
composer install

# Настройка окружения
cp .env.example .env
php artisan key:generate

# База данных
php artisan migrate --seed

# Первый администратор на чистой базе
php artisan shield:super-admin --panel=admin
```

При необходимости импорта из старого приложения поместите доверенные SQL-файлы
в закрытый каталог `storage/app/private/legacy-sql` и запустите
`php artisan db:seed --class=LegacySqlImportSeeder`. Новым импортированным
пользователям нужно отдельно настроить доступ. Храните дампы вне Git.

### Тестирование Telegram бота
```bash
# Автоматическая настройка
php artisan bot:start-testing

# Или вручную:
# 1. Получите токен у @BotFather
# 2. Добавьте в .env: TELEGRAM_TOKEN=ваш_токен
# 3. Запустите ngrok: ngrok http 8000
# 4. Установите webhook: php artisan telegram:webhook https://xxx.ngrok.io/botman
```

## 🔧 Основные команды

```bash
# Информация о боте
php artisan telegram:info

# Настройка webhook
php artisan telegram:webhook <url>

# Тестирование компонентов
php artisan bot:test

# Запуск сервера
php artisan serve
```

## 🌐 Доступ

- **Telegram бот:** https://t.me/medical_center_test_bot
- **Админ-панель:** http://localhost:8000/admin
- **API:** http://localhost:8000/api/v1/

## 📋 Функционал

### Telegram бот
- Запись на прием к врачу
- Просмотр информации о врачах
- Система отзывов с оценками
- Промо-коды
- Deep links для отзывов

### API
- Список городов и клиник
- Создание заявок
- CRUD операции с webhook
- Совместимость с оригинальным Flask API

### Админ-панель
- Управление всеми сущностями
- Просмотр заявок и отзывов
- Статистика и отчеты

## 📚 Документация

Полный контекст проекта: [PROJECT_CONTEXT.md](PROJECT_CONTEXT.md)

## 🛠️ Архитектура

- **Модели:** User, Application, City, Clinic, Doctor, Review, Webhook
- **Бот:** BotMan framework с file storage
- **Админка:** Filament
- **API:** Laravel Resources + Controllers

## 🧪 Тестирование

```bash
# Unit тесты
php artisan test

# Тестирование бота локально
php artisan bot:test-locally --message="/start"
```

## 📦 Развертывание

✅ **Автодеплой настроен и работает!** Каждый push в main автоматически обновляет продакшн.

Готово к развертыванию с Docker или на традиционном хостинге.
См. документацию по настройке продакшн среды.
