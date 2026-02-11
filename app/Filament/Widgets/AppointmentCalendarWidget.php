<?php

namespace App\Filament\Widgets;

use App\Models\Application;
use App\Models\ApplicationStatus;
use App\Models\Branch;
use App\Models\Cabinet;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\DoctorShift;
use App\Models\OnecSlot;
use App\Models\ExternalMapping;
use App\Services\Admin\AdminApplicationService;
use App\Modules\OnecSync\Contracts\CancellationConflictResolver;
use App\Services\OneC\Exceptions\OneCBookingException;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Виджет календаря записи пациентов
 *
 * Основной функционал:
 * - Отображает календарь смен врачей с временными слотами
 * - Показывает занятые и свободные слоты разными цветами
 * - Позволяет создавать новые заявки в свободных слотах
 * - Позволяет просматривать и редактировать существующие заявки
 * - Разграничивает права доступа по ролям пользователей
 *
 * Роли и их возможности:
 * - super_admin: полный доступ ко всем заявкам и сменам
 * - partner: доступ только к заявкам своей клиники
 * - doctor: только просмотр своих заявок, без возможности создания/редактирования
 */
class AppointmentCalendarWidget extends BaseAppointmentCalendarWidget
{
    /**
     * Фильтры для календаря заявок
     * Сохраняют состояние фильтрации по клиникам, филиалам, врачам и датам
     */
    public array $filters = [
        'clinic_ids' => [],
        'branch_ids' => [],
        'doctor_ids' => [],
        'date_from' => null,
        'date_to' => null,
    ];

    /**
     * Локальный кэш отображаемых имён врачей, чтобы не дёргать базу для каждого поля.
     *
     * @var array<int, string|null>
     */
    protected array $doctorNameCache = [];

    /**
     * Слушатели событий для обновления календаря
     */
    protected $listeners = ['refetchEvents', 'filtersUpdated', 'onec-force-delete' => 'handleForceDeleteLocal'];

    /**
     * Конфигурация календаря FullCalendar
     *
     * Настройки отображения и поведения календаря:
     * - Интерфейс на русском языке
     * - Рабочие часы с 8:00 до 20:00
     * - Слоты по 15 минут для точного планирования
     * - Отключено стандартное редактирование (используем свои формы)
     * - Несколько видов отображения (неделя, день, месяц, список)
     */
    public function config(): array
    {
        return $this->makeAppointmentCalendarConfig();
    }

    /**
     * Получить события для календаря
     *
     * Основная логика:
     * 1. Получаем смены врачей в запрошенном диапазоне дат
     * 2. Фильтруем по правам доступа пользователя
     * 3. Для каждой смены генерируем временные слоты
     * 4. Проверяем занятость каждого слота
     * 5. Формируем события для календаря с цветовой индикацией
     *
     * @param  array  $fetchInfo  Массив с датами начала и конца периода
     * @return array Массив событий для календаря
     */
    public function fetchEvents(array $fetchInfo): array
    {
        return $this->generateCalendarEvents($fetchInfo, $this->filters);
    }

    /**
     * Обработчик обновления фильтров
     */
    public function filtersUpdated($filters): void
    {
        $this->filters = $filters;
        $this->refreshRecords();
    }

    /**
     * Слушатель события из уведомления «Удалить и здесь?».
     * Удаляет запись без обращения в 1С и обновляет календарь.
     */
    protected function handleForceDeleteLocal(array $payload): void
    {
        $applicationId = (int) ($payload['applicationId'] ?? 0);

        if ($applicationId === 0) {
            return;
        }

        $application = Application::query()->find($applicationId);

        if (! $application) {
            Notification::make()
                ->title('Заявка не найдена')
                ->warning()
                ->send();

            return;
        }

        $application->delete();

        Notification::make()
            ->title('Заявка удалена локально')
            ->body('Запись удалена только в приложении (1С уже очистила слот).')
            ->success()
            ->send();

        $this->refreshRecords();
    }

    /**
     * Пробует распознать ошибку отмены как «запись уже удалена в 1С».
     * Возвращает true, если конфликт обработан (UI показан, повторно ничего делать не нужно).
     */
    protected function resolveCancellationConflict(Application $application, OneCBookingException $exception): bool
    {
        /** @var CancellationConflictResolver $resolver */
        $resolver = app(CancellationConflictResolver::class);
        $conflict = $resolver->buildConflictPayload($exception);

        if (! $conflict) {
            return false;
        }

        $this->handleCancellationConflict($application, $conflict);

        return true;
    }

    /**
     * Показывает предупреждение и кнопку, которая диспатчит событие с ID заявки.
     */
    protected function handleCancellationConflict(Application $application, array $conflict): void
    {
        Notification::make()
            ->title('Запись уже удалена в 1С')
            ->body($conflict['message'] ?? '1С уже удалила эту запись. Удалить её и у нас?')
            ->warning()
            ->actions([
                // Action диспатчит Livewire-событие, которое примет метод handleForceDeleteLocal().
                NotificationAction::make('force-delete-local-'.$application->id)
                    ->label('Удалить и здесь?')
                    ->color('danger')
                    ->button()
                    ->dispatch('onec-force-delete', [
                        'applicationId' => $application->id,
                    ]),
            ])
            ->send();
    }

    protected function attemptCancelInOneC(Application $application): bool
    {
        \Log::info('OneC cancel attempt', [
            'application_id' => $application->id,
            'external_appointment_id' => $application->external_appointment_id,
            'branch_id' => $application->branch_id,
        ]);
        $application->refresh();

        $branch = $application->branch()->with('integrationEndpoint')->first();
        $endpoint = $branch?->integrationEndpoint;
        $hasExternal = (bool) $application->external_appointment_id;
        $onecActive = $branch && $endpoint && $endpoint->type === \App\Models\IntegrationEndpoint::TYPE_ONEC && $endpoint->is_active;

        if ($hasExternal && $onecActive) {
            try {
                $response = app(\App\Services\OneC\OneCBookingService::class)->cancel($application);
                $statusCode = (int) Arr::get($response, 'status_code', 0);
                \Log::info('OneC cancel response', [
                    'application_id' => $application->id,
                    'status_code' => $statusCode,
                ]);
                if ($statusCode !== 204) {
                    Notification::make()
                        ->title('Не удалось отменить запись')
                        ->body('1С не подтвердила отмену (status_code: '.$statusCode.')')
                        ->danger()
                        ->send();
                    return false;
                }
                return true;
            } catch (OneCBookingException $e) {
                \Log::warning('OneC cancel failed', [
                    'application_id' => $application->id,
                    'error' => $e->getMessage(),
                ]);
                if ($this->resolveCancellationConflict($application, $e)) {
                    return false;
                }

                Notification::make()
                    ->title('Ошибка 1С')
                    ->body('Не удалось отменить запись в 1С: '.$e->getMessage())
                    ->danger()
                    ->send();

                return false;
            }
        }

        return true;
    }

    /**
     * Схема формы для создания и редактирования заявок
     *
     * Определяет структуру формы с полями:
     * - Выбор города, клиники, филиала, кабинета (каскадная зависимость)
     * - Выбор врача (зависит от кабинета)
     * - Дата и время приема
     * - Данные пациента (ФИО, телефон, дата рождения)
     * - Промокод
     *
     * @return array Массив компонентов формы
     */
    public function getFormSchema(): array
    {
        return [
            Grid::make(2)
                ->schema([
                    Select::make('city_id')
                        ->label('Город')
                        ->searchable() // Поиск по названию города
                        ->options(function () {
                            return \App\Models\City::pluck('name', 'id')->toArray();
                        })
                        ->reactive() // Поле реагирует на изменения
                        ->disabled() // Отключено для редактирования (заполняется автоматически)
                        ->dehydrated(false) // Не сохраняется в форме (только для отображения)
                        ->afterStateUpdated(fn (Set $set) => $set('clinic_id', null)), // Сброс зависимых полей

                    Select::make('clinic_id')
                        ->label('Клиника')
                        ->searchable() // Поиск по названию клиники
                        ->options(function (Get $get) {
                            // Получаем клиники только для выбранного города
                            $cityId = $get('city_id');
                            if (! $cityId) {
                                return [];
                            }

                            return \App\Models\Clinic::whereIn('id', function ($q) use ($cityId) {
                                $q->select('clinic_id')->from('branches')->where('city_id', $cityId);
                            })->pluck('name', 'id')->toArray();
                        })
                        ->reactive() // Поле реагирует на изменения
                        ->disabled() // Отключено для редактирования
                        ->dehydrated(false) // Не сохраняется в форме
                        ->afterStateUpdated(fn (Set $set) => $set('branch_id', null)), // Сброс филиала при изменении клиники

                    Select::make('branch_id')
                        ->label('Филиал')
                        ->searchable() // Поиск по названию филиала
                        ->options(function (Get $get) {
                            // Получаем филиалы только для выбранной клиники
                            $clinicId = $get('clinic_id');
                            if (! $clinicId) {
                                return [];
                            }

                            return \App\Models\Branch::where('clinic_id', $clinicId)->pluck('name', 'id')->toArray();
                        })
                        ->reactive() // Поле реагирует на изменения
                        ->disabled() // Отключено для редактирования
                        ->dehydrated(false) // Не сохраняется в форме
                        ->afterStateUpdated(fn (Set $set) => $set('cabinet_id', null)), // Сброс кабинета при изменении филиала

                    Select::make('cabinet_id')
                        ->label('Кабинет')
                        ->searchable() // Поиск по названию кабинета
                        ->options(function (Get $get) {
                            // Получаем кабинеты только для выбранного филиала
                            $branchId = $get('branch_id');
                            if (! $branchId) {
                                return [];
                            }

                            return \App\Models\Cabinet::where('branch_id', $branchId)->pluck('name', 'id')->toArray();
                        })
                        ->reactive() // Поле реагирует на изменения
                        ->disabled() // Отключено для редактирования
                        ->dehydrated(false) // Не сохраняется в форме
                        ->afterStateUpdated(fn (Set $set) => $set('doctor_id', null)), // Сброс врача при изменении кабинета

                    Select::make('doctor_id')
                        ->label('Врач')
                        ->disabled() // Отключено для редактирования
                        ->dehydrated(false) // Не сохраняется в форме
                        ->searchable() // Поиск по ФИО врача
                        ->options(function (Get $get) {
                            // Получаем врачей только для выбранного кабинета
                            $cabinetId = $get('cabinet_id');
                            if (! $cabinetId) {
                                return [];
                            }
                            $cabinet = \App\Models\Cabinet::with('branch.doctors')->find($cabinetId);
                            if (! $cabinet || ! $cabinet->branch) {
                                return [];
                            }

                            return $cabinet->branch->doctors->pluck('full_name', 'id')->toArray();
                        }),

                    DateTimePicker::make('appointment_datetime')
                        ->label('Дата и время приема')
                        ->seconds(false) // Не показывать секунды
                        ->minutesStep(5) // Шаг 5 минут
                        ->displayFormat('d.m.Y H:i') // Формат отображения
                        ->native(false) // Использовать кастомный виджет
                        ->disabled() // Отключено для редактирования
                        ->dehydrated(false), // Не сохраняется в форме

                ]),

            // Вторая сетка с данными пациента
            Grid::make(2)
                ->schema([
                    TextInput::make('full_name_parent')
                        ->label('ФИО родителя'), // ФИО родителя/опекуна

                    TextInput::make('full_name')
                        ->label('ФИО ребенка')
                        ->required(), // Обязательное поле

                    TextInput::make('birth_date')
                        ->label('Дата рождения')
                        ->type('date'), // Поле выбора даты

                    TextInput::make('phone')
                        ->label('Телефон')
                        ->tel() // Тип поля для телефона
                        ->required(), // Обязательное поле

                    TextInput::make('promo_code')
                        ->label('Промокод'), // Необязательное поле для скидок
                ]),
        ];
    }

    /**
     * Модальные действия для событий календаря
     *
     * Определяет стандартные действия (редактирование, удаление) для событий.
     * Врачи не имеют доступа к этим действиям - для них возвращается пустой массив.
     *
     * @return array Массив действий или пустой массив для врачей
     */
    protected function modalActions(): array
    {
        $user = auth()->user();

        // Врач может только просматривать - возвращаем пустой массив, чтобы не было стандартных действий
        if ($user->isDoctor()) {
            return [];
        }

        return [
            \Saade\FilamentFullCalendar\Actions\EditAction::make()
                ->mountUsing(function (\Filament\Forms\Form $form, array $arguments) {
                    $form->fill([
                        'city_id' => $this->record->city_id,
                        'clinic_id' => $this->record->clinic_id,
                        'branch_id' => $this->record->branch_id,
                        'cabinet_id' => $this->record->cabinet_id,
                        'doctor_id' => $this->record->doctor_id,
                        'appointment_datetime' => $this->normalizeEventTime($arguments['event']['start'] ?? $this->record->appointment_datetime, isset($arguments['event']['start'])),
                        'full_name' => $this->record->full_name,
                        'phone' => $this->record->phone,
                        'full_name_parent' => $this->record->full_name_parent,
                        'birth_date' => $this->record->birth_date,
                        'promo_code' => $this->record->promo_code,
                    ]);
                })
                ->action(function (array $data) {
                    $user = auth()->user();

                    // Проверяем права доступа
                    if ($user->isPartner() && $this->record->clinic_id !== $user->clinic_id) {
                        Notification::make()
                            ->title('Ошибка доступа')
                            ->body('Вы можете редактировать заявки только своей клиники')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->record->update($data);

                    Notification::make()
                        ->title('Заявка обновлена')
                        ->body('Заявка успешно обновлена')
                        ->success()
                        ->send();

                    $this->refreshRecords();
                }),

            \Saade\FilamentFullCalendar\Actions\DeleteAction::make()
                ->action(function () {
                    $user = auth()->user();

                    if ($user->isPartner() && $this->record->clinic_id !== $user->clinic_id) {
                        Notification::make()
                            ->title('Ошибка доступа')
                            ->body('Вы можете удалять заявки только своей клиники')
                            ->danger()
                            ->send();

                        return;
                    }

                    $application = $this->record;
                    $application->refresh();

                    $branch = $application->branch()->with('integrationEndpoint')->first();
                    $endpoint = $branch?->integrationEndpoint;
                    $hasExternal = (bool) $application->external_appointment_id;
                    $onecActive = $branch && $endpoint && $endpoint->type === \App\Models\IntegrationEndpoint::TYPE_ONEC && $endpoint->is_active;

                    if ($hasExternal && $onecActive) {
                        try {
                            $response = app(\App\Services\OneC\OneCBookingService::class)->cancel($application);
                            $statusCode = (int) \Illuminate\Support\Arr::get($response, 'status_code', 0);
                            if ($statusCode !== 204) {
                                Notification::make()
                                    ->title('Не удалось удалить запись')
                                    ->body('1С не подтвердила отмену (status_code: '.$statusCode.')')
                                    ->danger()
                                    ->send();

                                return;
                            }
                        } catch (\App\Services\OneC\Exceptions\OneCBookingException $e) {
                            if ($this->resolveCancellationConflict($application, $e)) {
                                return;
                            }

                            Notification::make()
                                ->title('Ошибка 1С')
                                ->body('Не удалось отменить запись в 1С: '.$e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }
                    }

                    $application->delete();

                    Notification::make()
                        ->title('Заявка удалена')
                        ->body('Заявка удалена из календаря')
                        ->success()
                        ->send();

                    $this->refreshRecords();
                }),
        ];
    }

    /**
     * Обработка клика по событию в календаре
     *
     * Основная логика:
     * 1. Определяет тип слота (занятый или свободный)
     * 2. Для занятых слотов - открывает форму просмотра/редактирования
     * 3. Для свободных слотов - открывает форму создания новой заявки
     * 4. Проверяет права доступа пользователя
     * 5. Проверяет, не прошла ли запись
     *
     * @param  array  $data  Данные события календаря
     */
    public function onEventClick(array $data): void
    {
        $user = auth()->user();
        $event = $data;
        $extendedProps = $event['extendedProps'] ?? [];

        // Проверяем, не прошла ли запись
        if ((bool) ($extendedProps['is_past'] ?? false)) {
            Notification::make()
                ->title('Прошедшая запись')
                ->body('Нельзя редактировать прошедшие записи')
                ->warning()
                ->send();

            return;
        }

        // Если слот занят - открываем форму просмотра/редактирования
        if ((bool) ($extendedProps['is_occupied'] ?? false)) {
            $this->onOccupiedSlotClick($extendedProps);

            return;
        }

        // Если слот свободен - открываем форму создания новой заявки
        // Проверяем права доступа: врачи не могут создавать заявки
        if ($user->isDoctor()) {
            Notification::make()
                ->title('Ограничение')
                ->body('Врачи не могут создавать заявки')
                ->warning()
                ->send();

            return;
        }

        $slotStart = $this->normalizeEventTime($extendedProps['slot_start'] ?? null);

        // Если слот пришёл из 1С, заполняем данные напрямую из extendedProps
        if (($extendedProps['source'] ?? null) === 'onec') {
            $this->slotData = $this->buildOneCSlotData($extendedProps, $slotStart);
        } else {
            $shift = DoctorShift::with(['cabinet.branch.clinic', 'cabinet.branch.city', 'doctor'])
                ->find($extendedProps['shift_id'] ?? null);

            if (! $shift) {
                Notification::make()
                    ->title('Ошибка')
                    ->body('Смена врача не найдена')
                    ->danger()
                    ->send();

                return;
            }

            $this->slotData = [
                'city_id' => $shift->cabinet->branch->city_id,
                'city_name' => $shift->cabinet->branch->city->name,
                'clinic_id' => $shift->cabinet->branch->clinic_id,
                'clinic_name' => $shift->cabinet->branch->clinic->name,
                'branch_id' => $shift->cabinet->branch_id,
                'branch_name' => $shift->cabinet->branch->name,
                'cabinet_id' => $shift->cabinet_id,
                'cabinet_name' => $shift->cabinet->name,
                'doctor_id' => $shift->doctor_id,
                'doctor_name' => $shift->doctor->full_name,
                'appointment_datetime' => $slotStart,
                'onec_payload' => null,
            ];
        }

        // Открываем форму для создания записи
        $this->mountAction('createAppointment');
    }

    /**
     * Обработка клика по занятому слоту
     *
     * Находит заявку по кабинету и времени, заполняет данные формы
     * и открывает модальное окно для просмотра/редактирования.
     * Учитывает права доступа пользователя при поиске заявки.
     *
     * @param  array  $data  Данные слота с информацией о кабинете и времени
     */
    public function onOccupiedSlotClick(array $data): void
    {
        $user = auth()->user();
        $extendedProps = $data;

        // Проверяем, есть ли данные заявки в событии
        if (isset($extendedProps['application_id']) && $extendedProps['application_id']) {

            // Используем данные из события, но загружаем полную модель для редактирования
            $application = Application::with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
                ->find($extendedProps['application_id']);

            if (! $application) {
                if (($extendedProps['source'] ?? null) === 'onec') {
                    $slotStart = $this->normalizeEventTime($extendedProps['slot_start'] ?? null);
                    $this->slotData = $this->buildOneCSlotData($extendedProps, $slotStart, true);
                    $this->record = null;
                    $this->mountAction('viewAppointment');

                    return;
                }

                Notification::make()
                    ->title('Ошибка')
                    ->body('Заявка не найдена')
                    ->danger()
                    ->send();

                return;
            }

            // Проверяем права доступа
            if ($user->isPartner() && $application->clinic_id !== $user->clinic_id) {
                Notification::make()
                    ->title('Ошибка доступа')
                    ->body('Вы можете просматривать только заявки своей клиники')
                    ->danger()
                    ->send();

                return;
            } elseif ($user->isDoctor() && $application->doctor_id !== $user->doctor_id) {
                Notification::make()
                    ->title('Ошибка доступа')
                    ->body('Вы можете просматривать только свои заявки')
                    ->danger()
                    ->send();

                return;
            }
        } else {
            // Fallback: ищем заявку по времени (старый способ)

            $slotStart = $this->normalizeEventTime($extendedProps['slot_start'] ?? null);
            $slotStartForQuery = $this->convertToUtcDateTime($slotStart);

            if (! $slotStartForQuery) {
                Notification::make()
                    ->title('Ошибка')
                    ->body('Не удалось определить время записи')
                    ->danger()
                    ->send();

                return;
            }

            $applicationQuery = Application::query()
                ->with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
                ->where('cabinet_id', $extendedProps['cabinet_id'])
                ->where('appointment_datetime', $slotStartForQuery);

            // Сначала ищем заявку без фильтрации по ролям
            $application = $applicationQuery->first();

            if ($application) {
                // Проверяем права доступа после нахождения заявки
                if ($user->isPartner() && $application->clinic_id !== $user->clinic_id) {
                    $application = null;
                } elseif ($user->isDoctor() && $application->doctor_id !== $user->doctor_id) {
                    $application = null;
                }
            }

            if (! $application) {
                if (($extendedProps['source'] ?? null) === 'onec') {
                    $this->slotData = $this->buildOneCSlotData($extendedProps, $slotStart, true);
                    $this->record = null;
                    $this->mountAction('viewAppointment');
                } else {
                    Notification::make()
                        ->title('Ошибка')
                        ->body('Заявка не найдена')
                        ->danger()
                        ->send();
                }

                return;
            }
        }

        // Заполняем данные для формы просмотра/редактирования
        $this->slotData = [
            'application_id' => $application->id,
            'city_id' => $application->city_id,
            'city_name' => $application->city?->name,
            'clinic_id' => $application->clinic_id,
            'clinic_name' => $application->clinic?->name,
            'branch_id' => $application->branch_id,
            'branch_name' => $application->branch?->name,
            'cabinet_id' => $application->cabinet_id,
            'cabinet_name' => $application->cabinet?->name,
            'doctor_id' => $application->doctor_id,
            'doctor_name' => $application->doctor?->full_name,
            'appointment_datetime' => $this->normalizeEventTime($application->appointment_datetime),
            'full_name' => $application->full_name,
            'phone' => $application->phone,
            'full_name_parent' => $application->full_name_parent,
            'birth_date' => $application->birth_date,
            'promo_code' => $application->promo_code,
            'appointment_status' => $application->appointment_status,
            'onec_payload' => null,
        ];

        // Устанавливаем запись для действий
        $this->record = $application;

        // Открываем модальное окно для просмотра/редактирования заявки
        $this->mountAction('viewAppointment');
    }

    /**
     * Действия в заголовке виджета
     *
     * Определяет кнопки действий в заголовке календаря:
     * - Для врачей: только кнопка просмотра информации о записи
     * - Для партнеров и админов: кнопки создания, просмотра и редактирования заявок
     *
     * Каждое действие имеет свою форму и логику обработки данных.
     *
     * @return array Массив действий для заголовка
     */
    protected function headerActions(): array
    {
        $user = auth()->user();

        // Врач не может создавать заявки, но может просматривать
        if ($user->isDoctor()) {
            return [
                \Filament\Actions\Action::make('viewAppointment')
                    ->label('Информация о записи')
                    ->icon('heroicon-o-eye')
                    ->visible(fn () => auth()->user()->isDoctor() || auth()->user()->isPartner() || auth()->user()->isSuperAdmin())
                    ->modalCancelActionLabel('Закрыть')
                    ->form([
                        Grid::make(2)
                            ->schema([
                                Select::make('city_id')
                                    ->label('Город')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->options(function () {
                                        return \App\Models\City::pluck('name', 'id')->toArray();
                                    }),

                                Select::make('clinic_id')
                                    ->label('Клиника')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->options(function (Get $get) {
                                        $cityId = $get('city_id');
                                        if (! $cityId) {
                                            return [];
                                        }

                                        return \App\Models\Clinic::whereIn('id', function ($q) use ($cityId) {
                                            $q->select('clinic_id')->from('branches')->where('city_id', $cityId);
                                        })->pluck('name', 'id')->toArray();
                                    }),

                                Select::make('branch_id')
                                    ->label('Филиал')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->options(function (Get $get) {
                                        $clinicId = $get('clinic_id');
                                        if (! $clinicId) {
                                            return [];
                                        }

                                        return \App\Models\Branch::where('clinic_id', $clinicId)->pluck('name', 'id')->toArray();
                                    }),

                                Select::make('cabinet_id')
                                    ->label('Кабинет')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->options(function (Get $get) {
                                        $branchId = $get('branch_id');
                                        if (! $branchId) {
                                            return [];
                                        }

                                        return \App\Models\Cabinet::where('branch_id', $branchId)->pluck('name', 'id')->toArray();
                                    }),

                                Hidden::make('doctor_id'),
                                TextInput::make('doctor_name_display')
                                    ->label('Врач')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->default(fn () => $this->getDoctorDisplayName($this->slotData['doctor_id'] ?? null, $this->slotData['doctor_name'] ?? null)),

                                DateTimePicker::make('appointment_datetime')
                                    ->label('Дата и время приема')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->seconds(false)
                                    ->minutesStep(5),
                            ]),

                        Grid::make(2)
                            ->schema([
                                TextInput::make('full_name_parent')
                                    ->label('ФИО родителя')
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('full_name')
                                    ->label('ФИО ребенка')
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('birth_date')
                                    ->label('Дата рождения')
                                    ->type('date')
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('phone')
                                    ->label('Телефон')
                                    ->tel()
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('promo_code')
                                    ->label('Промокод')
                                    ->disabled()
                                    ->dehydrated(false),

                                TextInput::make('appointment_status')
                                    ->label('Статус приема')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->formatStateUsing(fn ($state) => $this->record ? $this->record->getStatusLabel() : 'Неизвестно'),
                            ]),
                    ])
                    ->mountUsing(function (\Filament\Forms\Form $form) {
                        // Заполняем форму данными из слота
                        if (! empty($this->slotData)) {
                            // Для просмотра заполняем все поля
                            $form->fill([
                                'city_id' => $this->slotData['city_id'] ?? null,
                                'clinic_id' => $this->slotData['clinic_id'] ?? null,
                                'branch_id' => $this->slotData['branch_id'] ?? null,
                                'cabinet_id' => $this->slotData['cabinet_id'] ?? null,
                                'doctor_id' => $this->slotData['doctor_id'] ?? null,
                                'appointment_datetime' => $this->normalizeEventTime($this->slotData['appointment_datetime'] ?? null),
                                'full_name' => $this->slotData['full_name'] ?? '',
                                'phone' => $this->slotData['phone'] ?? '',
                                'full_name_parent' => $this->slotData['full_name_parent'] ?? '',
                                'birth_date' => $this->slotData['birth_date'] ?? '',
                                'promo_code' => $this->slotData['promo_code'] ?? '',
                                'appointment_status' => $this->slotData['appointment_status'] ?? null,
                            ]);
                        }
                    })
                    ->extraModalFooterActions([
                        // Кнопка "Начать прием"
                        \Filament\Actions\Action::make('startAppointment')
                            ->label('Начать прием')
                            ->icon('heroicon-o-play')
                            ->color('success')
                            ->visible(function () {
                                return $this->record && $this->record->isScheduled() && (auth()->user()->isDoctor() || auth()->user()->isPartner() || auth()->user()->isSuperAdmin());
                            })
                            ->action(function () {
                                if ($this->record && $this->record->startAppointment()) {
                                    \Filament\Notifications\Notification::make()
                                        ->title('Прием начат')
                                        ->body('Прием пациента успешно начат')
                                        ->success()
                                        ->send();
                                    $this->refreshRecords();
                                    $this->mountedAction = null;
                                } else {
                                    \Filament\Notifications\Notification::make()
                                        ->title('Ошибка')
                                        ->body('Не удалось начать прием')
                                        ->danger()
                                        ->send();
                                }
                            }),

                        // Кнопка "Завершить прием"
                        \Filament\Actions\Action::make('completeAppointment')
                            ->label('Завершить прием')
                            ->icon('heroicon-o-check-circle')
                            ->color('warning')
                            ->visible(function () {
                                return $this->record && $this->record->isInProgress() && (auth()->user()->isDoctor() || auth()->user()->isPartner() || auth()->user()->isSuperAdmin());
                            })
                            ->action(function () {
                                if ($this->record && $this->record->completeAppointment()) {
                                    \Filament\Notifications\Notification::make()
                                        ->title('Прием завершен')
                                        ->body('Прием пациента успешно завершен')
                                        ->success()
                                        ->send();
                                    $this->refreshRecords();
                                    $this->mountedAction = null;
                                } else {
                                    \Filament\Notifications\Notification::make()
                                        ->title('Ошибка')
                                        ->body('Не удалось завершить прием')
                                        ->danger()
                                        ->send();
                                }
                            }),

                        // Кнопка "Редактировать"
                        \Filament\Actions\Action::make('edit_application')
                            ->label('Редактировать')
                            ->color('warning')
                            ->icon('heroicon-o-pencil')
                            ->visible(fn () => $this->record && (auth()->user()->isSuperAdmin() || (auth()->user()->isPartner() && $this->record->clinic_id === auth()->user()->clinic_id)))
                            ->form([
                                Grid::make(2)
                                    ->schema([
                                        Select::make('city_id')
                                            ->label('Город')
                                            ->options(function () {
                                                return \App\Models\City::pluck('name', 'id')->toArray();
                                            })
                                            ->reactive()
                                            ->afterStateUpdated(function (\Filament\Forms\Set $set) {
                                                $set('clinic_id', null);
                                                $set('branch_id', null);
                                                $set('cabinet_id', null);
                                                $set('doctor_id', null);
                                            }),

                                        Select::make('clinic_id')
                                            ->label('Клиника')
                                            ->options(function (Get $get) {
                                                $cityId = $get('city_id');
                                                if (! $cityId) {
                                                    return [];
                                                }

                                                return \App\Models\Clinic::whereIn('id', function ($q) use ($cityId) {
                                                    $q->select('clinic_id')->from('branches')->where('city_id', $cityId);
                                                })->pluck('name', 'id')->toArray();
                                            })
                                            ->reactive()
                                            ->afterStateUpdated(function (\Filament\Forms\Set $set) {
                                                $set('branch_id', null);
                                                $set('cabinet_id', null);
                                                $set('doctor_id', null);
                                            }),

                                        Select::make('branch_id')
                                            ->label('Филиал')
                                            ->options(function (Get $get) {
                                                $clinicId = $get('clinic_id');
                                                if (! $clinicId) {
                                                    return [];
                                                }

                                                return \App\Models\Branch::where('clinic_id', $clinicId)->pluck('name', 'id')->toArray();
                                            })
                                            ->reactive()
                                            ->afterStateUpdated(function (\Filament\Forms\Set $set) {
                                                $set('cabinet_id', null);
                                                $set('doctor_id', null);
                                            }),

                                        Select::make('cabinet_id')
                                            ->label('Кабинет')
                                            ->options(function (Get $get) {
                                                $branchId = $get('branch_id');
                                                if (! $branchId) {
                                                    return [];
                                                }

                                                return \App\Models\Cabinet::where('branch_id', $branchId)->pluck('name', 'id')->toArray();
                                            })
                                            ->reactive()
                                            ->afterStateUpdated(function (\Filament\Forms\Set $set) {
                                                $set('doctor_id', null);
                                            }),

                                        Select::make('doctor_id')
                                            ->label('Врач')
                                            ->options(function (Get $get) {
                                                $clinicId = $get('clinic_id');
                                                if (! $clinicId) {
                                                    return [];
                                                }

                                                return \App\Models\Doctor::whereHas('clinics', function ($query) use ($clinicId) {
                                                    $query->where('clinic_id', $clinicId);
                                                })->get()->mapWithKeys(function ($doctor) {
                                                    return [$doctor->id => $doctor->full_name];
                                                })->toArray();
                                            }),

                                        DateTimePicker::make('appointment_datetime')
                                            ->label('Дата и время приема')
                                            ->displayFormat('d.m.Y H:i')
                                            ->native(false)
                                            ->seconds(false),
                                        TextInput::make('onec_slot_id'),
                                    ]),

                                Grid::make(2)
                                    ->schema([
                                        TextInput::make('full_name')
                                            ->label('ФИО пациента')
                                            ->required()
                                            ->maxLength(255),

                                        TextInput::make('phone')
                                            ->label('Телефон')
                                            ->tel()
                                            ->required()
                                            ->maxLength(20),

                                        TextInput::make('full_name_parent')
                                            ->label('ФИО родителя/представителя')
                                            ->maxLength(255),

                                        TextInput::make('birth_date')
                                            ->label('Дата рождения')
                                            ->maxLength(50),

                                        TextInput::make('promo_code')
                                            ->label('Промо-код')
                                            ->maxLength(50),
                                    ]),
                            ])
                            ->mountUsing(function (\Filament\Forms\Form $form) {
                                if ($this->record) {
                                    $form->fill([
                                        'city_id' => $this->record->city_id,
                                        'clinic_id' => $this->record->clinic_id,
                                        'branch_id' => $this->record->branch_id,
                                        'cabinet_id' => $this->record->cabinet_id,
                                        'doctor_id' => $this->record->doctor_id,
                                        'appointment_datetime' => $this->record->appointment_datetime,
                                        'full_name' => $this->record->full_name,
                                        'phone' => $this->record->phone,
                                        'full_name_parent' => $this->record->full_name_parent,
                                        'birth_date' => $this->record->birth_date,
                                    'promo_code' => $this->record->promo_code,
                                    'onec_slot_id' => null,
                                    ]);
                                }
                            })
                        ->action(function (array $data) {
                            $user = auth()->user();

                            if ($user->isPartner() && $this->record->clinic_id !== $user->clinic_id) {
                                Notification::make()
                                    ->title('Ошибка доступа')
                                    ->body('Вы можете редактировать заявки только своей клиники')
                                    ->danger()
                                    ->send();

                                return;
                            }

                            $onecSlotId = $data['onec_slot_id']
                                ?? ($this->slotData['onec_slot_id'] ?? null);
                            unset($data['onec_slot_id']);

                            $normalizedTime = $this->normalizeEventTime($data['appointment_datetime'] ?? null);
                            if ($normalizedTime && $this->record && $this->record->appointment_datetime && $normalizedTime->ne($this->record->appointment_datetime)) {
                                \Log::info('Edit detected time change', [
                                    'application_id' => $this->record->id,
                                    'old_time' => $this->record->appointment_datetime,
                                    'new_time' => $normalizedTime,
                                ]);
                                $onecSlotId = null;
                                $data['appointment_datetime'] = $normalizedTime;
                            }

                            // если слот не найден, пойдём прямой записью на указанное время

                            DB::beginTransaction();

                            try {
                                $application = $this->record->refresh();

                                $slot = $onecSlotId
                                    ? OnecSlot::query()
                                        ->where('external_slot_id', $onecSlotId)
                                        ->first()
                                    : null;

                                if ($slot) {
                                    $data['clinic_id'] = $slot->clinic_id;
                                    $data['branch_id'] = $slot->branch_id;
                                    $data['cabinet_id'] = $slot->cabinet_id;
                                    $data['doctor_id'] = $slot->doctor_id;
                                    $data['appointment_datetime'] = $slot->start_at;
                                    $data['city_id'] = Branch::find($slot->branch_id)?->city_id;
                                    \Log::info('Sync application with OneC slot', [
                                        'application_id' => $application->id,
                                        'onec_slot_id' => $onecSlotId,
                                        'slot_start' => $slot->start_at,
                                    ]);
                                }

                                $newData = array_merge($data, [
                                    'source' => $data['source'] ?? $application->source ?? 'admin',
                                    'send_to_1c' => $application->send_to_1c ?? false,
                                ]);

                                $updatedApplication = app(AdminApplicationService::class)->update($application, $newData, [
                                    'onec_slot_id' => $onecSlotId,
                                    'appointment_source' => 'Админка',
                                ]);
                                \Log::info('Updated application after edit', [
                                    'application_id' => $updatedApplication->id,
                                ]);

                                DB::commit();

                                $this->record = $updatedApplication;

                                Notification::make()
                                    ->title('Заявка обновлена')
                                    ->body('Заявка перенесена на новый слот.')
                                    ->success()
                                    ->send();

                                $this->refreshRecords();
                                $this->mountedAction = null;

                            } catch (ValidationException $exception) {
                                DB::rollBack();

                                Notification::make()
                                    ->title('Запись не сохранена')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();
                            } catch (OneCBookingException $exception) {
                                DB::rollBack();

                                Notification::make()
                                    ->title('1С отклонила запись')
                                    ->body($exception->getMessage())
                                    ->danger()
                                    ->send();
                            } catch (\Throwable $exception) {
                                DB::rollBack();
                                report($exception);

                                Notification::make()
                                    ->title('Ошибка')
                                    ->body('Не удалось обновить заявку: '.$exception->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),

                        // Кнопка "Удалить"
                        \Filament\Actions\Action::make('delete_application')
                            ->label('Удалить')
                            ->color('danger')
                            ->icon('heroicon-o-trash')
                            ->visible(fn () => $this->record && (auth()->user()->isSuperAdmin() || (auth()->user()->isPartner() && $this->record->clinic_id === auth()->user()->clinic_id)))
                            ->requiresConfirmation()
                            ->modalHeading('Удаление заявки')
                            ->modalDescription('Вы уверены, что хотите удалить эту заявку? Это действие нельзя отменить.')
                            ->action(function () {
                                if (! $this->record) {
                                    return;
                                }

                                $application = $this->record;
                                $application->refresh();

                                $branch = $application->branch()->with('integrationEndpoint')->first();
                                $endpoint = $branch?->integrationEndpoint;
                                $hasExternal = (bool) $application->external_appointment_id;
                                $onecActive = $branch && $endpoint && $endpoint->type === \App\Models\IntegrationEndpoint::TYPE_ONEC && $endpoint->is_active;

                                if ($hasExternal && $onecActive) {
                                    try {
                                        $response = app(\App\Services\OneC\OneCBookingService::class)->cancel($application);
                                        $statusCode = (int) \Illuminate\Support\Arr::get($response, 'status_code', 0);
                                        if ($statusCode !== 204) {
                                            Notification::make()
                                                ->title('Не удалось удалить запись')
                                                ->body('1С не подтвердила отмену (status_code: '.$statusCode.')')
                                                ->danger()
                                                ->send();

                                            return;
                                        }
                                        } catch (\App\Services\OneC\Exceptions\OneCBookingException $e) {
                                            if ($this->resolveCancellationConflict($application, $e)) {
                                                return;
                                            }

                                            Notification::make()
                                                ->title('Ошибка 1С')
                                                ->body('Не удалось отменить запись в 1С: '.$e->getMessage())
                                                ->danger()
                                                ->send();

                                            return;
                                        }
                                }

                                $application->delete();

                                Notification::make()
                                    ->title('Заявка удалена')
                                    ->body('Заявка удалена из календаря')
                                    ->success()
                                    ->send();

                                $this->refreshRecords();
                                $this->mountedAction = null;
                            }),

                    ]),
            ];
        }

        return [
            \Filament\Actions\Action::make('filters')
                ->label('Фильтры')
                ->icon('heroicon-o-funnel')
                ->color('gray')
                ->form([
                    \Filament\Forms\Components\Grid::make(3)
                        ->schema([
                            \Filament\Forms\Components\DatePicker::make('date_from')
                                ->label('Дата с')
                                ->displayFormat('d.m.Y')
                                ->native(false),

                            \Filament\Forms\Components\DatePicker::make('date_to')
                                ->label('Дата по')
                                ->displayFormat('d.m.Y')
                                ->native(false),

                            \Filament\Forms\Components\Select::make('clinic_ids')
                                ->label('Клиники')
                                ->multiple()
                                ->searchable()
                                ->reactive()
                                ->options(fn () => $this->getFilterService()->getAvailableClinics(auth()->user()))
                                ->afterStateUpdated(function (\Filament\Forms\Set $set) {
                                    $set('branch_ids', []);
                                    $set('doctor_ids', []);
                                }),
                        ]),

                    \Filament\Forms\Components\Grid::make(2)
                        ->schema([
                            \Filament\Forms\Components\Select::make('branch_ids')
                                ->label('Филиалы')
                                ->multiple()
                                ->searchable()
                                ->reactive()
                                ->options(function (\Filament\Forms\Get $get) {
                                    $clinicIds = $get('clinic_ids');

                                    return $this->getFilterService()->getAvailableBranches(auth()->user(), $clinicIds);
                                })
                                ->afterStateUpdated(fn (\Filament\Forms\Set $set) => $set('doctor_ids', [])),

                            \Filament\Forms\Components\Select::make('doctor_ids')
                                ->label('Врачи')
                                ->multiple()
                                ->searchable()
                                ->reactive()
                                ->options(function (\Filament\Forms\Get $get) {
                                    $branchIds = $get('branch_ids');

                                    return $this->getFilterService()->getAvailableDoctors(auth()->user(), $branchIds);
                                }),
                        ]),
                ])
                ->mountUsing(function (\Filament\Forms\Form $form) {
                    $form->fill($this->filters);
                })
                ->action(function (array $data) {
                    $this->filters = $data;
                    $this->refreshRecords();

                    \Filament\Notifications\Notification::make()
                        ->title('Фильтры применены')
                        ->success()
                        ->send();
                })
                ->extraModalFooterActions([
                    \Filament\Actions\Action::make('clearFilters')
                        ->label('Очистить фильтры')
                        ->color('gray')
                        ->action(function () {
                            $this->filters = [
                                'clinic_ids' => [],
                                'branch_ids' => [],
                                'doctor_ids' => [],
                                'date_from' => null,
                                'date_to' => null,
                            ];
                            $this->refreshRecords();

                            \Filament\Notifications\Notification::make()
                                ->title('Фильтры очищены')
                                ->success()
                                ->send();
                        }),
                ]),
        ];
    }

    /**
     * Обработчик события обновления календаря
     *
     * Вызывается при необходимости принудительного обновления событий календаря.
     * Например, после создания, редактирования или удаления заявки.
     * Использует Livewire dispatch для обновления компонента.
     */
    public function refetchEvents()
    {
        // Принудительно обновляем события календаря
        $this->dispatch('$refresh');
    }

    /**
     * Принудительное обновление календаря
     */
    public function forceRefresh()
    {
        // Очищаем кэш календаря
        $this->clearCalendarCache();

        // Принудительно обновляем события календаря
        $this->dispatch('$refresh');
    }

    /**
     * Принудительное обновление календаря с очисткой кэша браузера
     */
    public function forceRefreshWithCacheClear()
    {
        // Очищаем кэш календаря
        $this->clearCalendarCache();

        // Принудительно обновляем события календаря
        $this->dispatch('$refresh');

        // Отправляем JavaScript для очистки кэша браузера
        $this->dispatch('calendar-clear-cache');
    }

    /**
     * Очистка кэша календаря
     */
    private function clearCalendarCache()
    {
        // Очищаем все ключи кэша календаря
        $keys = \Illuminate\Support\Facades\Cache::get('calendar_cache_keys', []);

        foreach ($keys as $key) {
            \Illuminate\Support\Facades\Cache::forget($key);
        }

        // Очищаем ключ со списком ключей
        \Illuminate\Support\Facades\Cache::forget('calendar_cache_keys');
    }

    private function buildOneCSlotData(array $extendedProps, ?Carbon $slotStart, bool $includeDetails = false, ?int $applicationId = null): array
    {
        $rawPayload = $extendedProps['raw'] ?? [];

        $branchIdentifier = $extendedProps['branch_id']
            ?? Arr::get($rawPayload, 'branch_id')
            ?? Arr::get($rawPayload, 'slot.branch_external_id')
            ?? Arr::get($rawPayload, 'slot.branch_id');

        $branch = $this->resolveBranch($branchIdentifier);

        $clinic = $branch?->clinic;
        if (! $clinic && ! empty($extendedProps['clinic_id'])) {
            $clinic = Clinic::find($extendedProps['clinic_id']);
        }
        if (! $clinic) {
            $clinicExternal = Arr::get($rawPayload, 'clinic_external_id')
                ?? Arr::get($rawPayload, 'clinic_id');
            if ($clinicExternal) {
                $clinic = Clinic::where('external_id', $clinicExternal)->first();
            }
        }

        $cabinetIdentifier = $extendedProps['cabinet_id']
            ?? Arr::get($rawPayload, 'cabinet_id')
            ?? Arr::get($rawPayload, 'slot.cabinet_external_id');
        $cabinet = $this->resolveCabinet($cabinetIdentifier, $branch?->id);

        $doctorIdentifier = $extendedProps['doctor_id']
            ?? Arr::get($rawPayload, 'doctor_id')
            ?? Arr::get($rawPayload, 'doctor.external_id')
            ?? Arr::get($rawPayload, 'slot.doctor_external_id');
        $doctor = $this->resolveDoctor($doctorIdentifier, $clinic?->id);

        if (! $slotStart) {
            $slotStart = $this->buildDateFromPayload(
                Arr::get($rawPayload, 'cell.dt'),
                Arr::get($rawPayload, 'cell.time_start')
            );
        }

        $slotData = [
            'application_id' => $applicationId,
            'city_id' => $branch?->city_id ?? $extendedProps['city_id'] ?? $clinic?->branches()->value('city_id'),
            'city_name' => $branch?->city?->name ?? $extendedProps['city_name'] ?? Arr::get($rawPayload, 'city_name'),
            'clinic_id' => $clinic?->id ?? $branch?->clinic_id ?? $extendedProps['clinic_id'] ?? null,
            'clinic_name' => $clinic?->name ?? $branch?->clinic?->name ?? $extendedProps['clinic_name'] ?? Arr::get($rawPayload, 'clinic'),
            'branch_id' => $branch?->id ?? $extendedProps['branch_id'] ?? null,
            'branch_name' => $branch?->name ?? $extendedProps['branch_name'] ?? Arr::get($rawPayload, 'branch_name'),
            'cabinet_id' => $cabinet?->id ?? $extendedProps['cabinet_id'] ?? null,
            'cabinet_name' => $cabinet?->name ?? $extendedProps['cabinet_name'] ?? Arr::get($rawPayload, 'cell.cabinet_name'),
            'doctor_id' => $doctor?->id ?? $extendedProps['doctor_id'] ?? null,
            'doctor_name' => $doctor?->full_name ?? $extendedProps['doctor_name'] ?? Arr::get($rawPayload, 'doctor.efio'),
            'appointment_datetime' => $slotStart,
            'onec_slot_id' => $extendedProps['onec_slot_id'] ?? $extendedProps['slot_id'] ?? null,
            'source' => 'onec',
        ];

        if ($includeDetails) {
            $details = $this->extractOneCSlotDetails($extendedProps);
            $slotData = array_merge($slotData, $details);
        } else {
            $slotData['onec_payload'] = null;
        }

        return $slotData;
    }

    protected function resolveBranch(mixed $identifier): ?Branch
    {
        if (! $identifier) {
            return null;
        }

        $branch = Branch::with(['city', 'clinic'])->find($identifier);

        if ($branch) {
            return $branch;
        }

        $branch = Branch::with(['city', 'clinic'])
            ->where('external_id', (string) $identifier)
            ->first();

        if ($branch) {
            return $branch;
        }

        $mappedId = ExternalMapping::query()
            ->where('local_type', 'branch')
            ->where('external_id', (string) $identifier)
            ->value('local_id');

        return $mappedId ? Branch::with(['city', 'clinic'])->find($mappedId) : null;
    }

    protected function resolveDoctor(mixed $identifier, ?int $clinicId): ?Doctor
    {
        if (! $identifier) {
            return null;
        }

        $doctor = Doctor::find($identifier);

        if ($doctor) {
            return $doctor;
        }

        $doctor = Doctor::where('external_id', (string) $identifier)->first();

        if ($doctor || ! $clinicId) {
            return $doctor;
        }

        $mappedId = ExternalMapping::query()
            ->where('clinic_id', $clinicId)
            ->where('local_type', 'doctor')
            ->where('external_id', (string) $identifier)
            ->value('local_id');

        return $mappedId ? Doctor::find($mappedId) : null;
    }

    protected function resolveCabinet(mixed $identifier, ?int $branchId): ?Cabinet
    {
        if (! $identifier) {
            return null;
        }

        $cabinet = Cabinet::find($identifier);

        if ($cabinet) {
            return $cabinet;
        }

        $query = Cabinet::query()->where('external_id', (string) $identifier);

        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $cabinet = $query->first();

        if ($cabinet) {
            return $cabinet;
        }

        $mappedId = ExternalMapping::query()
            ->where('local_type', 'cabinet')
            ->where('external_id', (string) $identifier)
            ->value('local_id');

        return $mappedId ? Cabinet::find($mappedId) : null;
    }

    protected function applyFallbackSelectValue(\Filament\Forms\Form $form, string $field, ?string $label, string $fallbackKey): void
    {
        if (! $label) {
            return;
        }

        $component = $form->getComponent($field);

        if (! $component) {
            return;
        }

        $state = $component->getState();

        if ($state) {
            if (method_exists($component, 'options')) {
                $component->options([$state => $label]);
            }

            return;
        }

        if (method_exists($component, 'options')) {
            $component->options([$fallbackKey => $label]);
        }

        $component->state($fallbackKey);
    }

    protected function buildDateFromPayload(?string $date, ?string $time): ?Carbon
    {
        if (! $date || ! $time) {
            return null;
        }

        $timezone = config('app.timezone', 'UTC');

        try {
            return Carbon::createFromFormat('Y-m-d H:i', trim($date).' '.trim($time), $timezone);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected function getDoctorDisplayName(?int $doctorId, ?string $fallback = null): ?string
    {
        if ($doctorId) {
            if (! array_key_exists($doctorId, $this->doctorNameCache)) {
                $this->doctorNameCache[$doctorId] = Doctor::find($doctorId)?->full_name;
            }

            return $this->doctorNameCache[$doctorId] ?? $fallback;
        }

        return $fallback;
    }

    protected function extractOneCSlotDetails(array $extendedProps): array
    {
        $rawPayload = $extendedProps['raw'] ?? [];
        $cell = Arr::get($rawPayload, 'cell', []);
        $patient = Arr::get($rawPayload, 'patient', Arr::get($cell, 'patient', []));

        $fullName = Arr::get($patient, 'full_name')
            ?? Arr::get($cell, 'patient_full_name')
            ?? Arr::get($cell, 'fio')
            ?? Arr::get($cell, 'client_name');

        $parentName = Arr::get($patient, 'full_name_parent')
            ?? Arr::get($cell, 'full_name_parent')
            ?? Arr::get($cell, 'parent_full_name');

        $phone = Arr::get($patient, 'phone')
            ?? Arr::get($cell, 'phone')
            ?? Arr::get($cell, 'patient_phone');

        $birthDate = Arr::get($patient, 'birth_date')
            ?? Arr::get($cell, 'birth_date');

        $promoCode = Arr::get($patient, 'promo_code')
            ?? Arr::get($cell, 'promo_code');

        $status = Arr::get($extendedProps, 'appointment_status')
            ?? Arr::get($rawPayload, 'status')
            ?? Arr::get($cell, 'status');

        $normalizedStatus = is_string($status) ? mb_strtolower($status) : null;
        $statusLabel = match ($normalizedStatus) {
            'booked', 'занято', 'занят', 'occupied' => 'Занят (1С)',
            'cancelled', 'canceled', 'отменен', 'отменено' => 'Отменен (1С)',
            'free', 'свободен', 'available' => 'Свободен (1С)',
            default => is_string($status) ? $status : null,
        };

        return [
            'full_name' => $fullName ?? '',
            'full_name_parent' => $parentName ?? '',
            'phone' => $phone ?? '',
            'birth_date' => $birthDate ?? '',
            'promo_code' => $promoCode ?? '',
            'appointment_status' => $statusLabel,
            'onec_payload' => $this->formatOneCPayloadForDisplay($rawPayload),
        ];
    }

    protected function formatOneCPayloadForDisplay(?array $payload): ?string
    {
        if (empty($payload)) {
            return null;
        }

        try {
            return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $exception) {
            return print_r($payload, true);
        }
    }

    protected function convertToUtcDateTime(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        $timezone = config('app.timezone', 'UTC');

        if ($value instanceof CarbonInterface) {
            return $value->copy()->setTimezone('UTC')->toDateTimeString();
        }

        try {
            return Carbon::parse($value, $timezone)->setTimezone('UTC')->toDateTimeString();
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected function normalizeEventTime(mixed $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        $timezone = config('app.timezone', 'UTC');

        if ($value instanceof CarbonInterface) {
            return $value->copy()->setTimezone($timezone);
        }

        try {
            return Carbon::parse($value)->setTimezone($timezone);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected function getRecordOnecSlotId(): ?string
    {
        if ($slotId = Arr::get($this->slotData, 'onec_slot_id')) {
            return $slotId;
        }

        if ($this->record?->external_appointment_id) {
            $matched = OnecSlot::query()
                ->where('booking_uuid', $this->record->external_appointment_id)
                ->orWhere('external_slot_id', $this->record->external_appointment_id)
                ->value('external_slot_id');

            if ($matched) {
                return $matched;
            }
        }

        if ($this->record && $this->record->appointment_datetime) {
            return OnecSlot::query()
                ->when($this->record->clinic_id, fn ($query, $clinicId) => $query->where('clinic_id', $clinicId))
                ->when($this->record->branch_id, fn ($query, $branchId) => $query->where('branch_id', $branchId))
                ->when($this->record->doctor_id, fn ($query, $doctorId) => $query->where('doctor_id', $doctorId))
                ->where('start_at', $this->record->appointment_datetime)
                ->value('external_slot_id');
        }

        return null;
    }
}
