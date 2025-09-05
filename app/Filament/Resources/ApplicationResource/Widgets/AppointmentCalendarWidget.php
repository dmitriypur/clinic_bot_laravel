<?php

namespace App\Filament\Resources\ApplicationResource\Widgets;

use App\Models\Application;
use App\Models\DoctorShift;
use App\Models\Cabinet;
use App\Models\Doctor;
use App\Models\Branch;
use App\Models\Clinic;
use App\Services\CalendarFilterService;
use App\Services\CalendarEventService;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Grid;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Carbon\Carbon;

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
class AppointmentCalendarWidget extends FullCalendarWidget
{
    /**
     * Модель данных для работы с заявками
     */
    public \Illuminate\Database\Eloquent\Model | string | null $model = Application::class;
    
    /**
     * Временное хранилище данных выбранного слота
     * Используется для передачи информации между событиями календаря и формами
     */
    public array $slotData = [];
    
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
     * Слушатели событий для обновления календаря
     */
    protected $listeners = ['refetchEvents', 'filtersUpdated'];
    
    /**
     * Сервисы для работы с фильтрами и событиями
     */
    protected ?CalendarFilterService $filterService = null;
    protected ?CalendarEventService $eventService = null;
    
    public function getFilterService(): CalendarFilterService
    {
        if ($this->filterService === null) {
            $this->filterService = app(CalendarFilterService::class);
        }
        return $this->filterService;
    }
    
    protected function getEventService(): CalendarEventService
    {
        if ($this->eventService === null) {
            $this->eventService = app(CalendarEventService::class);
        }
        return $this->eventService;
    }

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
        $user = auth()->user();
        $isDoctor = $user && $user->isDoctor();
        
        return [
            'firstDay' => 1, // Понедельник - первый день недели
            'headerToolbar' => [
                'left' => 'prev,next today', // Кнопки навигации и "Сегодня"
                'center' => 'title', // Заголовок с текущим периодом
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' // Переключатели видов
            ],
            'initialView' => 'timeGridWeek', // По умолчанию показываем неделю
            'navLinks' => true, // Клик по дате переключает на день
            'editable' => false, // Отключаем стандартное редактирование событий
            'selectable' => false, // Отключаем выбор временных промежутков
            'selectMirror' => false, // Отключаем отображение выбранного времени
            'dayMaxEvents' => true, // Показывать "еще" если событий много
            'weekends' => true, // Показывать выходные дни
            'locale' => 'ru', // Русская локализация
            'buttonText' => [
                'today' => 'Сегодня',
                'month' => 'Месяц',
                'week' => 'Неделя',
                'day' => 'День',
                'list' => 'Список'
            ],
            'allDaySlot' => false, // Не показывать слот "Весь день"
            'slotMinTime' => '08:00:00', // Начало рабочего дня
            'slotMaxTime' => '20:00:00', // Конец рабочего дня
            'slotDuration' => '00:15:00', // Длительность слота 5 минут
            'snapDuration' => '00:05:00', // Привязка к 5-минутным интервалам
            'slotLabelFormat' => [ // Формат отображения времени в слотах
                'hour' => '2-digit',
                'minute' => '2-digit',
                'hour12' => false, // 24-часовой формат
            ],
            'eventDidMount' => 'function(info) {
                // Добавляем стили для прошедших записей
                if (info.event.extendedProps.is_past) {
                    info.el.style.opacity = "0.6";
                    info.el.style.filter = "grayscale(50%)";
                    if (info.event.extendedProps.is_occupied) {
                        info.el.title = "Прошедшая запись: " + info.event.title;
                    } else {
                        info.el.title = "Прошедший свободный слот";
                    }
                } else {
                    if (info.event.extendedProps.is_occupied) {
                        info.el.title = "Активная запись: " + info.event.title;
                    } else {
                        info.el.title = "Свободный слот для записи";
                    }
                }
                
                // Принудительно обновляем extendedProps для занятых слотов
                if (info.event.extendedProps.is_occupied && info.event.extendedProps.application_id) {
                    console.log("Обновляем extendedProps для события:", info.event.id, "application_id:", info.event.extendedProps.application_id);
                }
            }',
        ];
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
     * @param array $fetchInfo Массив с датами начала и конца периода
     * @return array Массив событий для календаря
     */
    public function fetchEvents(array $fetchInfo): array
    {
        $user = auth()->user();
        
        // Если пользователь не аутентифицирован, возвращаем пустой массив
        if (!$user) {
            return [];
        }
        
        // Добавляем уникальный идентификатор для принудительного обновления
        $fetchInfo['_timestamp'] = time();
        $fetchInfo['_random'] = uniqid();
        $fetchInfo['_cache_buster'] = md5(time() . rand());
        
        // Добавляем заголовки для предотвращения кэширования
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Используем сервис для генерации событий
        return $this->getEventService()->generateEvents($fetchInfo, $this->filters, $user);
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
                            if (!$cityId) return [];
                            return \App\Models\Clinic::whereIn('id', function($q) use ($cityId) {
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
                            if (!$clinicId) return [];
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
                            if (!$branchId) return [];
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
                            if (!$cabinetId) return [];
                            $cabinet = \App\Models\Cabinet::with('branch.doctors')->find($cabinetId);
                            if (!$cabinet || !$cabinet->branch) return [];
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
                        'appointment_datetime' => $arguments['event']['start'] ?? $this->record->appointment_datetime,
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
                    
                    // Проверяем права доступа
                    if ($user->isPartner() && $this->record->clinic_id !== $user->clinic_id) {
                        Notification::make()
                            ->title('Ошибка доступа')
                            ->body('Вы можете удалять заявки только своей клиники')
                            ->danger()
                            ->send();
                        return;
                    }
                    
                    $this->record->delete();
                    
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
     * @param array $data Данные события календаря
     */
    public function onEventClick(array $data): void
    {
        $user = auth()->user();
        $event = $data;
        $extendedProps = $event['extendedProps'] ?? [];
        
        // Проверяем, не прошла ли запись
        if (isset($extendedProps['is_past']) && $extendedProps['is_past']) {
            Notification::make()
                ->title('Прошедшая запись')
                ->body('Нельзя редактировать прошедшие записи')
                ->warning()
                ->send();
            return;
        }
        
        // Если слот занят - открываем форму просмотра/редактирования
        if (isset($extendedProps['is_occupied']) && $extendedProps['is_occupied']) {
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

        // Находим смену врача по ID из данных события
        // Загружаем связанные данные для заполнения формы
        $shift = DoctorShift::with(['cabinet.branch.clinic', 'cabinet.branch.city', 'doctor'])
            ->find($extendedProps['shift_id']);

        if (!$shift) {
            Notification::make()
                ->title('Ошибка')
                ->body('Смена врача не найдена')
                ->danger()
                ->send();
            return;
        }

        // Сохраняем данные слота в свойстве виджета для передачи в форму
        $slotStart = $extendedProps['slot_start'];
        if (is_string($slotStart)) {
            $slotStart = \Carbon\Carbon::parse($slotStart); // Преобразуем строку в объект Carbon
        }
        
        // Заполняем массив данными для формы создания заявки
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
        ];

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
     * @param array $data Данные слота с информацией о кабинете и времени
     */
    public function onOccupiedSlotClick(array $data): void
    {
        $user = auth()->user();
        $extendedProps = $data;
        
        // Отладочная информация
        \Log::info('onOccupiedSlotClick вызван', [
            'extendedProps' => $extendedProps,
            'user_id' => $user->id,
            'user_role' => $user->getRoleNames()->first()
        ]);
        
        // Проверяем, есть ли данные заявки в событии
        if (isset($extendedProps['application_id']) && $extendedProps['application_id']) {
            \Log::info('Ищем заявку по application_id', ['application_id' => $extendedProps['application_id']]);
            
            // Используем данные из события, но загружаем полную модель для редактирования
            $application = Application::with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
                ->find($extendedProps['application_id']);
                
            if (!$application) {
                \Log::error('Заявка не найдена по application_id', ['application_id' => $extendedProps['application_id']]);
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
            \Log::info('Ищем заявку по fallback методу', [
                'cabinet_id' => $extendedProps['cabinet_id'] ?? 'не указан',
                'slot_start' => $extendedProps['slot_start'] ?? 'не указан'
            ]);
            
            $slotStart = $extendedProps['slot_start'];
            if (is_string($slotStart)) {
                $slotStart = \Carbon\Carbon::parse($slotStart);
            }
            
            // Для MySQL: используем время слота как есть, так как в базе хранится локальное время
            // Для SQLite: конвертируем UTC в локальное время
            $slotStartForQuery = config('database.default') === 'mysql' 
                ? $slotStart->format('Y-m-d H:i:s')
                : $slotStart->setTimezone(config('app.timezone', 'UTC'));
            
            $applicationQuery = Application::query()
                ->with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
                ->where('cabinet_id', $extendedProps['cabinet_id'])
                ->where('appointment_datetime', $slotStartForQuery);
            
            // Сначала ищем заявку без фильтрации по ролям
            $application = $applicationQuery->first();
            
            if ($application) {
                // Проверяем права доступа после нахождения заявки
                if ($user->isPartner() && $application->clinic_id !== $user->clinic_id) {
                    \Log::info('Партнер не имеет доступа к заявке', [
                        'user_clinic_id' => $user->clinic_id,
                        'application_clinic_id' => $application->clinic_id
                    ]);
                    $application = null;
                } elseif ($user->isDoctor() && $application->doctor_id !== $user->doctor_id) {
                    \Log::info('Врач не имеет доступа к заявке', [
                        'user_doctor_id' => $user->doctor_id,
                        'application_doctor_id' => $application->doctor_id
                    ]);
                    $application = null;
                }
            }
            
            \Log::info('SQL запрос для поиска заявки', [
                'sql' => $applicationQuery->toSql(),
                'bindings' => $applicationQuery->getBindings()
            ]);

            if (!$application) {
                \Log::error('Заявка не найдена по fallback методу', [
                    'cabinet_id' => $extendedProps['cabinet_id'],
                    'slot_start' => $slotStart,
                    'user_role' => $user->getRoleNames()->first(),
                    'user_clinic_id' => $user->clinic_id ?? null,
                    'user_doctor_id' => $user->doctor_id ?? null
                ]);
                Notification::make()
                    ->title('Ошибка')
                    ->body('Заявка не найдена')
                    ->danger()
                    ->send();
                return;
            }
            
            \Log::info('Заявка найдена по fallback методу', ['application_id' => $application->id]);
        }

        // Заполняем данные для формы просмотра/редактирования
        $this->slotData = [
            'application_id' => $application->id,
            'city_id' => $application->city_id,
            'city_name' => $application->city->name,
            'clinic_id' => $application->clinic_id,
            'clinic_name' => $application->clinic->name,
            'branch_id' => $application->branch_id,
            'branch_name' => $application->branch->name,
            'cabinet_id' => $application->cabinet_id,
            'cabinet_name' => $application->cabinet->name,
            'doctor_id' => $application->doctor_id,
            'doctor_name' => $application->doctor->full_name,
            'appointment_datetime' => $application->appointment_datetime,
            'full_name' => $application->full_name,
            'phone' => $application->phone,
            'full_name_parent' => $application->full_name_parent,
            'birth_date' => $application->birth_date,
            'promo_code' => $application->promo_code,
        ];

        // Устанавливаем запись для действий
        $this->record = $application;
        
        // Открываем модальное окно для просмотра/редактирования заявки
        $this->mountAction('view');
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
                    ->visible(fn() => auth()->user()->isDoctor())
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
                                        if (!$cityId) return [];
                                        return \App\Models\Clinic::whereIn('id', function($q) use ($cityId) {
                                            $q->select('clinic_id')->from('branches')->where('city_id', $cityId);
                                        })->pluck('name', 'id')->toArray();
                                    }),
                                
                                Select::make('branch_id')
                                    ->label('Филиал')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->options(function (Get $get) {
                                        $clinicId = $get('clinic_id');
                                        if (!$clinicId) return [];
                                        return \App\Models\Branch::where('clinic_id', $clinicId)->pluck('name', 'id')->toArray();
                                    }),
                                
                                Select::make('cabinet_id')
                                    ->label('Кабинет')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->options(function (Get $get) {
                                        $branchId = $get('branch_id');
                                        if (!$branchId) return [];
                                        return \App\Models\Cabinet::where('branch_id', $branchId)->pluck('name', 'id')->toArray();
                                    }),
                                
                                Select::make('doctor_id')
                                    ->label('Врач')
                                    ->disabled()
                                    ->dehydrated(false)
                                    ->options(function (Get $get) {
                                        $cabinetId = $get('cabinet_id');
                                        if (!$cabinetId) return [];
                                        $cabinet = \App\Models\Cabinet::with('branch.doctors')->find($cabinetId);
                                        if (!$cabinet || !$cabinet->branch) return [];
                                        return $cabinet->branch->doctors->pluck('full_name', 'id')->toArray();
                                    }),
                                
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
                            ]),
                    ])
                    ->mountUsing(function (\Filament\Forms\Form $form) {
                        // Заполняем форму данными из слота
                        if (!empty($this->slotData)) {
                            // Для просмотра заполняем все поля
                            $form->fill([
                                'city_id' => $this->slotData['city_id'] ?? null,
                                'clinic_id' => $this->slotData['clinic_id'] ?? null,
                                'branch_id' => $this->slotData['branch_id'] ?? null,
                                'cabinet_id' => $this->slotData['cabinet_id'] ?? null,
                                'doctor_id' => $this->slotData['doctor_id'] ?? null,
                                'appointment_datetime' => $this->slotData['appointment_datetime'] ?? null,
                                'full_name' => $this->slotData['full_name'] ?? '',
                                'phone' => $this->slotData['phone'] ?? '',
                                'full_name_parent' => $this->slotData['full_name_parent'] ?? '',
                                'birth_date' => $this->slotData['birth_date'] ?? '',
                                'promo_code' => $this->slotData['promo_code'] ?? '',
                            ]);
                        }
                    })
                    ->extraModalFooterActions([
                        \Filament\Actions\Action::make('close')
                            ->label('Закрыть')
                            ->color('gray')
                            ->action(fn() => $this->closeModal()),
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
                                ->options(fn() => $this->getFilterService()->getAvailableClinics(auth()->user()))
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
                
            \Filament\Actions\Action::make('createAppointment')
                ->label('Создать заявку')
                ->icon('heroicon-o-plus')
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
                                    if (!$cityId) return [];
                                    return \App\Models\Clinic::whereIn('id', function($q) use ($cityId) {
                                        $q->select('clinic_id')->from('branches')->where('city_id', $cityId);
                                    })->pluck('name', 'id')->toArray();
                                }),
                            
                            Select::make('branch_id')
                                ->label('Филиал')
                                ->disabled()
                                ->dehydrated(false)
                                ->options(function (Get $get) {
                                    $clinicId = $get('clinic_id');
                                    if (!$clinicId) return [];
                                    return \App\Models\Branch::where('clinic_id', $clinicId)->pluck('name', 'id')->toArray();
                                }),
                            
                            Select::make('cabinet_id')
                                ->label('Кабинет')
                                ->disabled()
                                ->dehydrated(false)
                                ->options(function (Get $get) {
                                    $branchId = $get('branch_id');
                                    if (!$branchId) return [];
                                    return \App\Models\Cabinet::where('branch_id', $branchId)->pluck('name', 'id')->toArray();
                                }),
                            
                            Select::make('doctor_id')
                                ->label('Врач')
                                ->disabled()
                                ->dehydrated(false)
                                ->options(function (Get $get) {
                                    $cabinetId = $get('cabinet_id');
                                    if (!$cabinetId) return [];
                                    $cabinet = \App\Models\Cabinet::with('branch.doctors')->find($cabinetId);
                                    if (!$cabinet || !$cabinet->branch) return [];
                                    return $cabinet->branch->doctors->pluck('full_name', 'id')->toArray();
                                }),
                            
                            DateTimePicker::make('appointment_datetime')
                                ->label('Дата и время приема')
                                ->disabled()
                                ->dehydrated(false)
                                ->seconds(false)
                                ->minutesStep(5)
                                ->displayFormat('d.m.Y H:i'),
                        ]),
                    
                    Grid::make(2)
                        ->schema([
                            TextInput::make('full_name_parent')
                                ->label('ФИО родителя'),
                            
                            TextInput::make('full_name')
                                ->label('ФИО ребенка')
                                ->required(),
                            
                            TextInput::make('birth_date')
                                ->label('Дата рождения')
                                ->type('date'),
                            
                            TextInput::make('phone')
                                ->label('Телефон')
                                ->tel()
                                ->required(),
                            
                            TextInput::make('promo_code')
                                ->label('Промокод'),
                        ]),
                ])
                ->mountUsing(function (\Filament\Forms\Form $form) {
                    // Заполняем форму данными из слота
                    if (!empty($this->slotData)) {
                        // Для Select полей нужно передавать ID, а не название
                        $form->fill([
                            'city_id' => $this->slotData['city_id'] ?? null,
                            'clinic_id' => $this->slotData['clinic_id'] ?? null,
                            'branch_id' => $this->slotData['branch_id'] ?? null,
                            'cabinet_id' => $this->slotData['cabinet_id'] ?? null,
                            'doctor_id' => $this->slotData['doctor_id'] ?? null,
                            'appointment_datetime' => $this->slotData['appointment_datetime'] ?? null,
                        ]);
                    }
                })
                ->action(function (array $data) {
                    $user = auth()->user();
                    
                    // Проверяем обязательные поля
                    if (empty($data['full_name']) || empty($data['phone'])) {
                        Notification::make()
                            ->title('Ошибка валидации')
                            ->body('Пожалуйста, заполните ФИО ребенка и телефон')
                            ->danger()
                            ->send();
                        return;
                    }
                    
                    // Объединяем данные формы с данными из слота
                    $applicationData = array_merge($this->slotData ?? [], $data);
                    
                    // Отладочная информация
                    \Log::info('Создание заявки', [
                        'form_data' => $data,
                        'slot_data' => $this->slotData ?? [],
                        'merged_data' => $applicationData,
                        'user' => $user->id
                    ]);
                    
                    // Проверяем права доступа для партнеров
                    if ($user->isPartner()) {
                        // Проверяем, что создаваемая заявка относится к клинике партнера
                        if ($applicationData['clinic_id'] !== $user->clinic_id) {
                            Notification::make()
                                ->title('Ошибка доступа')
                                ->body('Вы можете создавать заявки только в своей клинике')
                                ->danger()
                                ->send();
                            return;
                        }
                    }
                    
                    try {
                        $application = Application::create($applicationData);
                        \Log::info('Заявка создана', ['id' => $application->id]);
                    } catch (\Exception $e) {
                        \Log::error('Ошибка создания заявки', [
                            'error' => $e->getMessage(),
                            'data' => $applicationData
                        ]);
                        
                        Notification::make()
                            ->title('Ошибка')
                            ->body('Не удалось создать заявку: ' . $e->getMessage())
                            ->danger()
                            ->send();
                        return;
                    }
                    
                    Notification::make()
                        ->title('Заявка создана')
                        ->body('Заявка успешно добавлена в календарь')
                        ->success()
                        ->send();
                        
                    $this->refreshRecords();
                }),
                
            \Filament\Actions\Action::make('viewAppointment')
                ->label('Информация о записи')
                ->icon('heroicon-o-eye')
                ->visible(fn() => auth()->user()->isDoctor())
                ->modalHeading('Информация о записи пациента')
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
                                    if (!$cityId) return [];
                                    return \App\Models\Clinic::whereIn('id', function($q) use ($cityId) {
                                        $q->select('clinic_id')->from('branches')->where('city_id', $cityId);
                                    })->pluck('name', 'id')->toArray();
                                }),
                            
                            Select::make('branch_id')
                                ->label('Филиал')
                                ->disabled()
                                ->dehydrated(false)
                                ->options(function (Get $get) {
                                    $clinicId = $get('clinic_id');
                                    if (!$clinicId) return [];
                                    return \App\Models\Branch::where('clinic_id', $clinicId)->pluck('name', 'id')->toArray();
                                }),
                            
                            Select::make('cabinet_id')
                                ->label('Кабинет')
                                ->disabled()
                                ->dehydrated(false)
                                ->options(function (Get $get) {
                                    $branchId = $get('branch_id');
                                    if (!$branchId) return [];
                                    return \App\Models\Cabinet::where('branch_id', $branchId)->pluck('name', 'id')->toArray();
                                }),
                            
                            Select::make('doctor_id')
                                ->label('Врач')
                                ->disabled()
                                ->dehydrated(false)
                                ->options(function (Get $get) {
                                    $cabinetId = $get('cabinet_id');
                                    if (!$cabinetId) return [];
                                    $cabinet = \App\Models\Cabinet::with('branch.doctors')->find($cabinetId);
                                    if (!$cabinet || !$cabinet->branch) return [];
                                    return $cabinet->branch->doctors->pluck('full_name', 'id')->toArray();
                                }),
                            
                            DateTimePicker::make('appointment_datetime')
                                ->label('Дата и время приема')
                                ->disabled()
                                ->dehydrated(false)
                                ->seconds(false)
                                ->minutesStep(15),
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
                        ]),
                ])
                ->mountUsing(function (\Filament\Forms\Form $form) {
                    Notification::make()
                        ->title('Отладка')
                        ->body('mountUsing вызван для viewAppointment')
                        ->info()
                        ->send();
                    
                    // Заполняем форму данными из слота
                    if (!empty($this->slotData)) {
                        // Для просмотра заполняем все поля
                        $form->fill([
                            'city_id' => $this->slotData['city_id'] ?? null,
                            'clinic_id' => $this->slotData['clinic_id'] ?? null,
                            'branch_id' => $this->slotData['branch_id'] ?? null,
                            'cabinet_id' => $this->slotData['cabinet_id'] ?? null,
                            'doctor_id' => $this->slotData['doctor_id'] ?? null,
                            'appointment_datetime' => $this->slotData['appointment_datetime'] ?? null,
                            'full_name' => $this->slotData['full_name'] ?? '',
                            'phone' => $this->slotData['phone'] ?? '',
                            'full_name_parent' => $this->slotData['full_name_parent'] ?? '',
                            'birth_date' => $this->slotData['birth_date'] ?? '',
                            'promo_code' => $this->slotData['promo_code'] ?? '',
                        ]);
                    }
                })
                ->extraModalFooterActions([
                    \Filament\Actions\Action::make('close')
                        ->label('Закрыть')
                        ->color('gray')
                        ->action(fn() => $this->closeModal()),
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
}
