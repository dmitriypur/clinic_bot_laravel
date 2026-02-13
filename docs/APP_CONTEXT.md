# APP_CONTEXT

Актуальный контекст проекта для быстрого входа в работу без полного повторного анализа кода.

## Что это за проект
- Продукт: медицинская запись (веб-форма для пациентов в Telegram WebApp + административная панель).
- Основная пользовательская воронка: пациент выбирает город/клинику/врача/слот, отправляет заявку, далее заявка обрабатывается в админке.
- Ключевая особенность: два режима расписания.
  - `local`: расписание строится по локальным сменам врачей (`doctor_shifts`).
  - `onec_push`: расписание и занятость приходят из 1С (webhook + `onec_slots`).

## Технологии и стек
- Backend: Laravel 11, PHP 8.3+.
- Admin: Filament 3 (+ Filament Shield для ролей/разрешений, FullCalendar widget).
- Frontend: Inertia + Vue 3 + Vite.
- Bot: BotMan + Telegram driver.
- Интеграции:
  - 1С расписание/бронирование.
  - CRM-уведомления (Bitrix24, AmoCRM, Albato, 1С CRM webhook).
- Очереди/кэш/сессии: стандартные драйверы Laravel (на dev обычно sqlite/database драйверы).

## Архитектурный срез
- Монолит Laravel.
- Публичный API: `routes/api.php` (`/api/v1/*`) для WebApp и интеграций.
- Веб-маршруты: `routes/web.php` (Inertia страница `/app`, BotMan webhook `/botman`, служебные/админ-роуты).
- Админ-панель Filament развёрнута на корне (`path('')`), с role-based доступом.
- Модуль 1С вынесен в `app/Modules/OnecSync/*` и подключается через provider.

## Основные доменные сущности
- `City`: город, статус активности.
- `Clinic`: клиника, режим интеграции, CRM-настройки, флаг доступности календаря.
- `Branch`: филиал клиники, `external_id`, режим интеграции, endpoint 1С, длительность слота.
- `Doctor`: врач, ФИО, `external_id`, связи с клиниками/филиалами.
- `Cabinet`: кабинет филиала, `external_id`.
- `DoctorShift`: локальные смены врачей (для local-режима).
- `OnecSlot`: слоты, пришедшие из 1С (включая статус, booking uuid, raw payload).
- `Application`: основная заявка пациента (включая интеграционные поля 1С/CRM).
- `ApplicationStatus`: справочник статусов заявки (type-aware, в т.ч. appointment статусы).
- `Appointment`: сущность процесса приема (in_progress/completed), синхронизируется с `Application`.
- `IntegrationEndpoint`: настройки интеграции филиала с 1С.
- `ExternalMapping`: сопоставления внешних ID с локальными сущностями.
- `CrmIntegrationLog`: логи отправок в CRM.
- `TelegramContact`: телефон/чат, полученные из Telegram contact.
- `Webhook`: пользовательские вебхуки (CRUD присутствует, контроллер пока пустой).
- `Export`: экспортные задачи/файлы Filament.
- `SystemSetting`: системные key/value настройки.

## Роли и доступ
- Базовые роли: `super_admin`, `admin`, `partner`, `doctor`.
- Доступ в Filament: `super_admin` или permission `access_filament`.
- Ограничения выборок по ролям активно применяются в ресурсах/виджетах/фильтрах:
  - `partner` видит свою клинику.
  - `doctor` видит свои смены/заявки.

## Режимы расписания
- Переключатель режима: `integration_mode` у клиники/филиала (`local`, `onec_push`).
- Эффективный режим филиала: филиал -> клиника -> default local.
- В `onec_push`:
  - источник слотов — `onec_slots`;
  - provider календаря — `OneCSlotProvider`;
  - вебхук расписания обязателен для актуализации.
- В `local`:
  - источник слотов — `doctor_shifts`;
  - provider календаря — `LocalSlotProvider`.

## Потоки 1С (важно)
### 1) Входящее расписание 1С (push)
- Endpoint: `POST /api/v1/integrations/{clinic}/schedule`.
- Проверки:
  - клиника в `onec_push`;
  - филиал найден по `branch_external_id` (`branches.external_id`);
  - филиал в `onec_push`;
  - у филиала есть `integrationEndpoint` 1С;
  - при заданном секрете — валидация `X-Integration-Token`.
- Поддерживаются 2 формата payload:
  - structured `slots[]`;
  - legacy `schedule.data` (через `OneCLegacyScheduleTransformer`).
- Минимально обязательные поля structured: `branch_external_id`, `slots[].slot_id`, `start_at`, `end_at`, `status`.
- Синхронизация выполняет upsert в `onec_slots`, отсутствующие в батче слоты помечаются `blocked`.

### 2) Входящие события бронирований 1С
- Endpoint: `POST /api/v1/integrations/{clinic}/bookings/webhook`.
- Обрабатываются события `booking_created|updated|cancelled`.
- Также поддержан формат `cells[]` (батч ячеек по врачу/дате).
- Модуль `OnecSync` может синхронизировать `claim_id` с локальными заявками и при `free` удалять локальную запись (фича-флаг).

### 3) Исходящее бронирование/отмена в 1С
- Выполняется через `OneCBookingService` + `OneCApiClient`.
- Критично:
  - филиал и активный endpoint 1С;
  - у врача должен быть `external_id`;
  - для slot booking — валидный слот.
- Поддерживается:
  - запись по выбранному слоту;
  - `bookDirect` (ручная запись по `appointment_datetime`, без `slot_id`).

## Сопоставление врачей при импорте слотов 1С
В `OneCSlotSyncService` реализована логика:
1. Сначала mapping по `ExternalMapping`/`doctor.external_id`.
2. Если не найдено, fallback по ФИО (`doctor.efio`/`doctor.name`) со строгим сравнением.
3. При совпадении врачу автоматически прописывается `external_id`.
4. Врач автоматически привязывается к клинике и филиалу (`syncWithoutDetaching`).

Следствие:
- Врачи не создаются автоматически из 1С, но могут автоматически сматчиться.
- Для стабильной работы рекомендуется заранее завести справочник врачей.

## Ключевые бизнес-правила заявок
- Создание заявки (`ApplicationController@store`) поддерживает локальный и 1С сценарий.
- В `onec_push` может требоваться `onec_slot_id` (в зависимости от контекста branch/endpoint/active).
- Проверка выбранного слота: `POST /api/v1/applications/check-slot`.
- Удаление 1С-заявок:
  - обычная попытка отменить в 1С;
  - при конфликте «уже удалено в 1С» возвращается `409` с `can_force_delete`, доступно локальное удаление.

## Календарь и расписание
### Админ-календарь заявок
- Генерация событий — `CalendarEventService` + `SlotProviderFactory`.
- Для каждого слота определяется локальная занятость (`applications`) + внешняя занятость (из 1С статуса).
- Основной виджет: `AppointmentCalendarWidget`.

### Календарь расписания врачей
- Виджеты: `AllCabinetsScheduleWidget`, `CabinetScheduleWidget`.
- Локальные смены управляются через `ShiftService`/`MassShiftCreator`.

### Кэш календаря
- Используется ключевой реестр `calendar_cache_keys` и selective flush на изменения моделей (`Application`, `DoctorShift`, `Cabinet`).
- Есть команда `calendar:clear-cache`.

## Telegram интеграция
- Webhook: `/botman`.
- Команда `/start` открывает диалог и WebApp кнопку.
- Обработка shared contact сохраняет `TelegramContact`.
- Сервис отправки уведомлений: `TelegramService` (прямой вызов Telegram Bot API).
- Уведомления по заявкам:
  - `SendAppointmentConfirmationNotification` при подтвержденном статусе;
  - `SendAppointmentReminderNotification` (за 2 часа, уникальная job).

## CRM интеграция
- Провайдер на уровне клиники (`crm_provider` + `crm_settings`).
- Dispatch: `CrmNotificationService` -> `SendCrmNotificationJob`.
- Фабрика провайдеров: Bitrix24, AmoCRM, Albato, OneCNotifier.
- Все попытки логируются в `crm_integration_logs`.

## WebApp фронт (Inertia/Vue)
- Страница: `resources/js/Pages/Booking.vue`.
- Пошаговый мастер с динамическими flow:
  - обычный flow: город -> возраст -> режим -> клиника/филиал -> врач -> слот -> подтверждение;
  - promo flow: укороченный сценарий.
- Ключевые API вызовы:
  - `/api/v1/cities`;
  - `/api/v1/cities/{city}/clinics`;
  - `/api/v1/clinics/{clinic}/branches`;
  - `/api/v1/clinics/{clinic}/doctors` и `/api/v1/cities/{city}/doctors`;
  - `/api/v1/doctors/{doctor}/slots`;
  - `/api/v1/applications/check-slot`;
  - `POST /api/v1/applications`.
- Контекст Telegram (`tg_user_id`, `tg_chat_id`, `phone`) подтягивается из query/hash/WebApp initData.

## Основные API маршруты
Файл: `routes/api.php`
- `cities`, `clinics`, `doctors`, `applications` (REST + доп. роуты).
- `GET /api/v1/doctors/{doctor}/slots`.
- `POST /api/v1/applications/check-slot`.
- `POST /api/v1/integrations/{clinic}/bookings/webhook`.
- `POST /api/v1/integrations/{clinic}/schedule`.

## Основные web маршруты
Файл: `routes/web.php`
- `GET /app` -> Inertia Booking.
- `GET|POST /botman` -> Telegram Bot webhook.
- CRUD-ish API для смен кабинета под auth (`cabinets/{cabinet}/shifts/*`).
- `GET /download/export/{exportId}` under auth.
- debug endpoint для 1С слотов филиала (`/debug/onec/{branch}/slots`, only super_admin).

## Админка Filament (ключевое)
- Ресурсы: города, клиники, филиалы, врачи, кабинеты, заявки, статусы заявок, приемы, пользователи, роли, отзывы.
- Отдельная сущность "Bid" для workflow заявок с интеграцией календаря.
- Dashboard:
  - переключаемая вкладка «Заявки / Расписание»;
  - флаг доступности календаря на пользователя/клинику;
  - виджет статуса интеграции 1С для super_admin.

## Консольные команды
- Telegram:
  - `telegram:info`
  - `telegram:webhook`
  - `bot:start-testing`
- Календарь:
  - `calendar:clear-cache`
- Экспорты:
  - `exports:check-table`
  - `exports:cleanup`
- Технические:
  - `fix:applications-id-auto-increment` (при наличии соответствующей команды в проекте)

Примечание:
- В `routes/console.php` нет прикладного schedule (кроме `inspire`). Если нужны периодические задачи, они пока не заведены через Laravel Scheduler.

## ENV и конфигурация (важное)
- Telegram:
  - `TELEGRAM_TOKEN`
  - `TELEGRAM_BOT_USERNAME`
  - `TELEGRAM_WEBHOOK_URL`
  - `TELEGRAM_WEB_APP_URL`
- 1С:
  - `ONEC_SYNC_ENABLED`
  - `ONEC_SYNC_AUTO_DELETE`
  - `ONEC_API_TIMEOUT`
- Календарь/мониторинг:
  - `CALENDAR_QUERY_LOGGING`
  - `CALENDAR_PERFORMANCE_MONITORING`
- CRM: на уровне `clinics.crm_provider` и `clinics.crm_settings`.

## Матрица статусов (фактическая)
### Application.appointment_status (legacy field)
- `scheduled`
- `in_progress`
- `completed`

### Appointment.status (enum)
- `in_progress`
- `completed`

### Application.integration_type
- `local`
- `onec`

### OnecSlot.status
- `free`
- `booked`
- `blocked`

### ApplicationStatus (справочник)
- Набор динамический из БД (`application_statuses`), используется `slug` + `type`.

## Известные технические особенности/долги
- `StoreShiftRequest`/`UpdateShiftRequest` валидируют `doctor_id` через `exists:users,id`, а сервисы/модели используют `doctors.id` (потенциальная несогласованность).
- `routes/console.php` не содержит прикладных scheduled задач.
- `WebhookController` (CRUD webhooks) пока пустой.
- README частично общий/устаревший относительно текущих модулей 1С/CRM.

## Рекомендованный операционный чек-лист для 1С push
1. Клиника/филиал переведены в `onec_push`.
2. У филиала заполнен `external_id`.
3. У филиала активен `integrationEndpoint` типа `onec`.
4. Заполнены endpoint credentials (`base_url`, auth/path, при необходимости `webhook_secret`).
5. Врачи заведены локально и сопоставлены (external_id или точное ФИО для первичного матча).
6. Проверен импорт `/api/v1/integrations/{clinic}/schedule` тестовым батчем.

## Правило сопровождения (обязательно)
После каждой новой фичи, изменения поведения или архитектуры:
1. Обновить раздел `Что изменилось (последнее)`.
2. Добавить запись в `Журнал изменений`.
3. При необходимости обновить разделы: маршруты, сервисы, env, ограничения.

## Что изменилось (последнее)
- 2026-02-13: создан `docs/APP_CONTEXT.md` с подробным контекстом проекта (архитектура, домен, интеграции 1С/CRM/Telegram, API, правила и операционные заметки).

## Журнал изменений
- 2026-02-13: первичное создание `docs/APP_CONTEXT.md`.
