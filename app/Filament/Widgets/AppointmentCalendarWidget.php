<?php

namespace App\Filament\Widgets;

use App\Models\Application;
use App\Models\ApplicationStatus;
use App\Models\DoctorShift;
use App\Models\Cabinet;
use App\Models\Doctor;
use App\Models\Branch;
use App\Models\Clinic;
use App\Modules\OnecSync\Contracts\CancellationConflictResolver;
use App\Services\Admin\AdminApplicationService;
use App\Services\OneC\Exceptions\OneCBookingException;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Grid;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
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
     * Слушатели событий для обновления календаря
     */
    protected $listeners = ['refetchEvents', 'filtersUpdated'];

    /**
     * Кэши для уменьшения повторных запросов в рамках жизненного цикла Livewire-компонента.
     *
     * @var array<int, array<int, string>>
     */
    protected array $doctorOptionsByBranchCache = [];

    /** @var array<int, int|null> */
    protected array $branchIdByCabinetCache = [];

    /** @var array<int, string|null> */
    protected array $doctorNameByIdCache = [];

    /** @var array<string, int|null> */
    protected array $doctorIdByExternalCache = [];

    /** @var array<string, int|null> */
    protected array $doctorIdByFullNameCache = [];
    
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
     * @param array $fetchInfo Массив с датами начала и конца периода
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
                        ->options(fn (Get $get) => $this->resolveDoctorOptions($get)),
                    
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
                    $record = $this->record ?? null;
                    if (! $record) {
                        return;
                    }
                    
                    // Проверяем права доступа
                    if ($user->isPartner() && $record->clinic_id !== $user->clinic_id) {
                        Notification::make()
                            ->title('Ошибка доступа')
                            ->body('Вы можете удалять заявки только своей клиники')
                            ->danger()
                            ->send();
                        return;
                    }

                    $this->deleteCurrentRecordWithOneCHandling(true);
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

        // Для слотов из 1С не ищем локальную смену, берём данные прямо из события.
        if ($this->isOnecEvent($extendedProps, $event)) {
            $this->slotData = $this->buildOnecSlotData($extendedProps);
            $this->mountAction('createAppointment');

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
        $slotStart = $this->normalizeEventTime($extendedProps['slot_start'] ?? null);
        
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
        
        // Проверяем, есть ли данные заявки в событии
        if (isset($extendedProps['application_id']) && $extendedProps['application_id']) {
            
            // Используем данные из события, но загружаем полную модель для редактирования
            $application = Application::with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
                ->find($extendedProps['application_id']);
                
            if (!$application) {
                if ($this->isOnecEvent($extendedProps)) {
                    $this->slotData = $this->buildOnecSlotData($extendedProps, true);
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

            if (!$slotStartForQuery) {
                Notification::make()
                    ->title('Ошибка')
                    ->body('Не удалось определить время записи')
                    ->danger()
                    ->send();
                return;
            }
            
            $applicationQuery = Application::query()
                ->with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
                ->where('appointment_datetime', $slotStartForQuery)
                ->when(isset($extendedProps['cabinet_id']) && $extendedProps['cabinet_id'] !== null, fn ($query) => $query->where('cabinet_id', $extendedProps['cabinet_id']))
                ->when(isset($extendedProps['doctor_id']) && $extendedProps['doctor_id'] !== null, fn ($query) => $query->where('doctor_id', $extendedProps['doctor_id']))
                ->when(isset($extendedProps['branch_id']) && $extendedProps['branch_id'] !== null, fn ($query) => $query->where('branch_id', $extendedProps['branch_id']))
                ->when(isset($extendedProps['clinic_id']) && $extendedProps['clinic_id'] !== null, fn ($query) => $query->where('clinic_id', $extendedProps['clinic_id']));
            
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
            

            if (!$application) {
                if ($this->isOnecEvent($extendedProps)) {
                    $this->slotData = $this->buildOnecSlotData($extendedProps, true);
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
            'cabinet_name' => $application->cabinet?->name ?? ($extendedProps['cabinet_name'] ?? null),
            'doctor_id' => $application->doctor_id,
            'doctor_name' => $application->doctor?->full_name ?? ($extendedProps['doctor_name'] ?? null),
            'appointment_datetime' => $this->normalizeEventTime($application->appointment_datetime),
            'full_name' => $application->full_name,
            'phone' => $application->phone,
            'full_name_parent' => $application->full_name_parent,
            'birth_date' => $application->birth_date,
            'promo_code' => $application->promo_code,
            'appointment_status' => $application->appointment_status,
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
                    ->visible(fn() => auth()->user()->isDoctor() || auth()->user()->isPartner() || auth()->user()->isSuperAdmin())
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
                                    ->options(fn (Get $get) => $this->resolveDoctorOptions($get)),
                                
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
                                    ->formatStateUsing(fn($state) => $this->record ? $this->record->getStatusLabel() : 'Неизвестно'),
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
                            ->visible(function() {
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
                            ->visible(function() {
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
                            ->visible(fn() => $this->record && (auth()->user()->isSuperAdmin() || (auth()->user()->isPartner() && $this->record->clinic_id === auth()->user()->clinic_id)))
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
                                                if (!$cityId) return [];
                                                return \App\Models\Clinic::whereIn('id', function($q) use ($cityId) {
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
                                                if (!$clinicId) return [];
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
                                                if (!$branchId) return [];
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
                                                if (!$clinicId) return [];
                                                return \App\Models\Doctor::whereHas('clinics', function($query) use ($clinicId) {
                                                    $query->where('clinic_id', $clinicId);
                                                })->get()->mapWithKeys(function($doctor) {
                                                    return [$doctor->id => $doctor->full_name];
                                                })->toArray();
                                            }),
                                        
                                        DateTimePicker::make('appointment_datetime')
                                            ->label('Дата и время приема')
                                            ->displayFormat('d.m.Y H:i')
                                            ->native(false)
                                            ->seconds(false),
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
                                    ]);
                                }
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
                                $this->mountedAction = null;
                            }),
                        
                        // Кнопка "Удалить"
                        \Filament\Actions\Action::make('delete_application')
                            ->label('Удалить')
                            ->color('danger')
                            ->icon('heroicon-o-trash')
                            ->visible(fn() => $this->record && (auth()->user()->isSuperAdmin() || (auth()->user()->isPartner() && $this->record->clinic_id === auth()->user()->clinic_id)))
                            ->requiresConfirmation()
                            ->modalHeading('Удаление заявки')
                            ->modalDescription('Вы уверены, что хотите удалить эту заявку? Это действие нельзя отменить.')
                            ->action(function () {
                                if ($this->record) {
                                    $this->deleteCurrentRecordWithOneCHandling(true);
                                }
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
                                ->options(fn (Get $get) => $this->resolveDoctorOptions($get)),
                            
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
                            'appointment_datetime' => $this->normalizeEventTime($this->slotData['appointment_datetime'] ?? null),
                        ]);
                    }
                })
                ->action(function (array $data) {
                    $user = auth()->user();
                    
                    $validated = Validator::make(
                        $data,
                        [
                            'full_name_parent' => ['nullable', 'string', 'max:255'],
                            'full_name' => ['required', 'string', 'max:255'],
                            'birth_date' => ['nullable', 'date'],
                            'phone' => ['required', 'string', 'max:25'],
                            'promo_code' => ['nullable', 'string', 'max:100'],
                        ],
                        [
                            'full_name.required' => 'Пожалуйста, заполните ФИО ребенка.',
                            'phone.required' => 'Пожалуйста, заполните телефон.',
                        ],
                    )->validate();

                    $validated['full_name'] = trim($validated['full_name'] ?? '');

                    if ($validated['full_name'] === '') {
                        throw ValidationException::withMessages([
                            'full_name' => 'Пожалуйста, заполните ФИО ребенка.',
                        ]);
                    }

                    if (array_key_exists('full_name_parent', $validated)) {
                        $validated['full_name_parent'] = trim($validated['full_name_parent'] ?? '');
                        if ($validated['full_name_parent'] === '') {
                            $validated['full_name_parent'] = null;
                        }
                    }

                    $validated['phone'] = trim($validated['phone'] ?? '');

                    if ($validated['phone'] === '') {
                        throw ValidationException::withMessages([
                            'phone' => 'Пожалуйста, заполните телефон.',
                        ]);
                    }

                    if (!empty($validated['birth_date'])) {
                        try {
                            $validated['birth_date'] = Carbon::parse($validated['birth_date'])->format('Y-m-d');
                        } catch (\Throwable $exception) {
                            // Оставляем исходное значение, если не удалось разобрать дату
                        }
                    }

                    $slotContext = Arr::only($this->slotData ?? [], [
                        'city_id',
                        'clinic_id',
                        'branch_id',
                        'cabinet_id',
                        'doctor_id',
                        'appointment_datetime',
                        'status_id',
                        'appointment_status',
                        'source',
                        'send_to_1c',
                    ]);

                    // Объединяем данные формы с данными из слота
                    $applicationData = array_merge($slotContext, $validated);

                    if (!empty($applicationData['appointment_datetime']) && ! $applicationData['appointment_datetime'] instanceof CarbonInterface) {
                        $applicationData['appointment_datetime'] = $this->normalizeEventTime($applicationData['appointment_datetime']);
                    }

                    $applicationData['source'] = $applicationData['source'] ?? 'admin';
                    $applicationData['send_to_1c'] = $applicationData['send_to_1c'] ?? false;

                    if (empty($applicationData['status_id'])) {
                        $status = ApplicationStatus::where('slug', 'appointment_scheduled')->first()
                            ?? ApplicationStatus::where('slug', 'appointment')->first()
                            ?? ApplicationStatus::where('slug', 'scheduled')->first()
                            ?? ApplicationStatus::where('slug', 'new')->first();

                        if ($status) {
                            $applicationData['status_id'] = $status->id;
                        }
                    }

                    if (empty($applicationData['appointment_status'])) {
                        $applicationData['appointment_status'] = Application::STATUS_SCHEDULED;
                    }
                    
                    
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
                    
                    $onecSlotId = $this->slotData['onec_slot_id'] ?? null;

                    try {
                        $application = app(AdminApplicationService::class)->create($applicationData, [
                            'onec_slot_id' => $onecSlotId,
                            'appointment_source' => 'Админка',
                        ]);
                    } catch (ValidationException $e) {
                        Notification::make()
                            ->title('Запись не сохранена')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        return;
                    } catch (OneCBookingException $e) {
                        Notification::make()
                            ->title('1С отклонила запись')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                        return;
                    } catch (\Throwable $e) {
                        \Log::error('Ошибка создания заявки', [
                            'error' => $e->getMessage(),
                            'data' => $applicationData,
                            'onec_slot_id' => $onecSlotId,
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
                    ->visible(fn() => auth()->user()->isDoctor() || auth()->user()->isPartner() || auth()->user()->isSuperAdmin())
                    ->modalHeading('Информация о записи пациента')
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
                                ->options(fn (Get $get) => $this->resolveDoctorOptions($get)),
                            
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
                    
                    // Поле статуса приема
                    TextInput::make('appointment_status')
                        ->label('Статус приема')
                        ->disabled()
                        ->dehydrated(false)
                        ->formatStateUsing(fn($state) => $this->record ? $this->record->getStatusLabel() : 'Неизвестно'),
                    
                    // Сообщение для завершенных приемов
                    \Filament\Forms\Components\Placeholder::make('completed_message')
                        ->label('')
                        ->content('Прием проведен')
                        ->visible(fn() => $this->record && $this->record->isCompleted())
                        ->extraAttributes([
                            'style' => 'text-align: center; font-size: 18px; font-weight: bold; color: #15803d; background-color: #dcfce7; padding: 12px; border-radius: 8px; border: 1px solid #bbf7d0;'
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
                    // Кнопка "Начать прием"
                    \Filament\Actions\Action::make('startAppointment')
                        ->label('Начать прием')
                        ->icon('heroicon-o-play')
                        ->color('success')
                        ->visible(function() {
                            return $this->record && $this->record->isScheduled() && (auth()->user()->isDoctor() || auth()->user()->isPartner() || auth()->user()->isSuperAdmin());
                        })
                        ->action(function () {
                            if ($this->record && $this->record->startAppointment()) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Прием начат')
                                    ->body('Прием пациента успешно начат')
                                    ->success()
                                    ->send();
                                
                                // Обновляем календарь
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
                        ->visible(function() {
                            return $this->record && $this->record->isInProgress() && (auth()->user()->isDoctor() || auth()->user()->isPartner() || auth()->user()->isSuperAdmin());
                        })
                        ->action(function () {
                            if ($this->record && $this->record->completeAppointment()) {
                                \Filament\Notifications\Notification::make()
                                    ->title('Прием завершен')
                                    ->body('Прием пациента успешно завершен')
                                    ->success()
                                    ->send();
                                
                                // Обновляем календарь
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
                        ->visible(fn() => $this->record && (auth()->user()->isSuperAdmin() || (auth()->user()->isPartner() && $this->record->clinic_id === auth()->user()->clinic_id)))
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
                                            if (!$cityId) return [];
                                            return \App\Models\Clinic::whereIn('id', function($q) use ($cityId) {
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
                                            if (!$clinicId) return [];
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
                                            if (!$branchId) return [];
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
                                            if (!$clinicId) return [];
                                            return \App\Models\Doctor::whereHas('clinics', function($query) use ($clinicId) {
                                                $query->where('clinic_id', $clinicId);
                                            })->get()->mapWithKeys(function($doctor) {
                                                return [$doctor->id => $doctor->full_name];
                                            })->toArray();
                                        }),
                                    
                                    DateTimePicker::make('appointment_datetime')
                                        ->label('Дата и время приема')
                                        ->displayFormat('d.m.Y H:i')
                                        ->native(false)
                                        ->seconds(false),
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
                                ]);
                            }
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
                            $this->mountedAction = null;
                        }),
                    
                    // Кнопка "Удалить"
                    \Filament\Actions\Action::make('delete_application')
                        ->label('Удалить')
                        ->color('danger')
                        ->icon('heroicon-o-trash')
                        ->visible(fn() => $this->record && (auth()->user()->isSuperAdmin() || (auth()->user()->isPartner() && $this->record->clinic_id === auth()->user()->clinic_id)))
                        ->requiresConfirmation()
                        ->modalHeading('Удаление заявки')
                        ->modalDescription('Вы уверены, что хотите удалить эту заявку? Это действие нельзя отменить.')
                        ->action(function () {
                            if ($this->record) {
                                $this->deleteCurrentRecordWithOneCHandling(true);
                            }
                        }),
                    
                ]),
        ];
    }

    /**
     * Скрываем служебные действия из шапки, но оставляем их доступными для mountAction по клику на слот.
     */
    public function getCachedHeaderActions(): array
    {
        $actions = parent::getCachedHeaderActions();

        return array_values(array_filter($actions, function ($action) {
            $name = method_exists($action, 'getName') ? $action->getName() : null;

            return ! in_array($name, ['createAppointment', 'viewAppointment'], true);
        }));
    }

    protected function deleteCurrentRecordWithOneCHandling(bool $closeModal = false): void
    {
        $record = $this->record ?? null;
        if (! $record) {
            return;
        }

        if (
            $record->integration_type === Application::INTEGRATION_TYPE_ONEC
            && filled($record->external_appointment_id)
        ) {
            try {
                app(AdminApplicationService::class)->cancelOneCBooking($record);
            } catch (OneCBookingException $exception) {
                $conflict = app(CancellationConflictResolver::class)->buildConflictPayload($exception);

                if ($conflict && ($conflict['can_force_delete'] ?? false)) {
                    Notification::make()
                        ->title('Запись уже удалена в 1С')
                        ->body($conflict['message'] ?? 'Удаляем запись только локально.')
                        ->warning()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Не удалось удалить запись в 1С')
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }
            }
        }

        $record->delete();
        $this->record = null;
        $this->slotData = [];

        Notification::make()
            ->title('Заявка удалена')
            ->body('Заявка удалена из календаря')
            ->success()
            ->send();

        $this->refreshRecords();

        if ($closeModal) {
            $this->mountedAction = null;
        }
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

    protected function convertToUtcDateTime(mixed $value): ?string
    {
        if (!$value) {
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
        if (!$value) {
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

    /**
     * Определяем, что событие календаря пришло из 1С.
     */
    protected function isOnecEvent(array $extendedProps, array $event = []): bool
    {
        $source = $extendedProps['source'] ?? $extendedProps['slot_source'] ?? null;
        if ($source === 'onec') {
            return true;
        }

        $slotId = $extendedProps['slot_id'] ?? $event['id'] ?? null;
        if (is_string($slotId) && str_starts_with($slotId, 'onec:')) {
            return true;
        }

        return ! empty($extendedProps['onec_slot_id']);
    }

    /**
     * Возвращает список врачей для readonly-форм.
     * В onec_push слот может приходить без кабинета, поэтому используем fallback через филиал/doctor_id.
     */
    protected function resolveDoctorOptions(Get $get): array
    {
        $doctorId = $get('doctor_id') ? (int) $get('doctor_id') : null;
        $cabinetId = $get('cabinet_id') ? (int) $get('cabinet_id') : null;
        $branchId = $get('branch_id') ? (int) $get('branch_id') : null;

        if (! $branchId && $cabinetId) {
            $branchId = $this->resolveBranchIdByCabinet($cabinetId);
        }

        $options = $branchId ? $this->getDoctorOptionsByBranch($branchId) : [];

        if ($doctorId && ! array_key_exists($doctorId, $options)) {
            $doctorName = $this->resolveDoctorNameById($doctorId);
            if ($doctorName) {
                $options[$doctorId] = $doctorName;
            }
        }

        return $options;
    }

    protected function resolveBranchIdByCabinet(int $cabinetId): ?int
    {
        if (array_key_exists($cabinetId, $this->branchIdByCabinetCache)) {
            return $this->branchIdByCabinetCache[$cabinetId];
        }

        $branchId = Cabinet::query()->whereKey($cabinetId)->value('branch_id');

        return $this->branchIdByCabinetCache[$cabinetId] = $branchId ? (int) $branchId : null;
    }

    protected function getDoctorOptionsByBranch(int $branchId): array
    {
        if (array_key_exists($branchId, $this->doctorOptionsByBranchCache)) {
            return $this->doctorOptionsByBranchCache[$branchId];
        }

        $doctors = Doctor::query()
            ->select(['doctors.id', 'doctors.last_name', 'doctors.first_name', 'doctors.second_name'])
            ->whereHas('branches', fn ($query) => $query->where('branches.id', $branchId))
            ->orderBy('doctors.last_name')
            ->orderBy('doctors.first_name')
            ->orderBy('doctors.second_name')
            ->get();

        $options = $doctors
            ->mapWithKeys(function (Doctor $doctor): array {
                $fullName = $doctor->full_name;
                $this->doctorNameByIdCache[$doctor->id] = $fullName;

                return [$doctor->id => $fullName];
            })
            ->toArray();

        return $this->doctorOptionsByBranchCache[$branchId] = $options;
    }

    protected function resolveDoctorNameById(int $doctorId): ?string
    {
        if (array_key_exists($doctorId, $this->doctorNameByIdCache)) {
            return $this->doctorNameByIdCache[$doctorId];
        }

        $doctor = Doctor::query()
            ->select(['id', 'last_name', 'first_name', 'second_name'])
            ->find($doctorId);

        return $this->doctorNameByIdCache[$doctorId] = $doctor?->full_name;
    }

    /**
     * Пытается определить локального врача для слота 1С.
     */
    protected function resolveOnecDoctorId(array $extendedProps): ?int
    {
        $doctorId = $extendedProps['doctor_id'] ?? null;

        if ($doctorId) {
            return (int) $doctorId;
        }

        $doctorExternalId = Arr::get($extendedProps, 'raw.doctor.external_id')
            ?? Arr::get($extendedProps, 'raw.doctor_external_id')
            ?? Arr::get($extendedProps, 'raw.doctor_id');

        if ($doctorExternalId) {
            $externalKey = (string) $doctorExternalId;

            if (! array_key_exists($externalKey, $this->doctorIdByExternalCache)) {
                $foundId = Doctor::query()
                    ->where('external_id', $externalKey)
                    ->value('id');
                $this->doctorIdByExternalCache[$externalKey] = $foundId ? (int) $foundId : null;
            }

            $doctorIdByExternal = $this->doctorIdByExternalCache[$externalKey];

            if ($doctorIdByExternal) {
                return (int) $doctorIdByExternal;
            }
        }

        $doctorName = trim((string) (
            $extendedProps['doctor_name']
            ?? Arr::get($extendedProps, 'raw.doctor.efio')
            ?? Arr::get($extendedProps, 'raw.doctor.name')
            ?? ''
        ));

        if ($doctorName === '') {
            return null;
        }

        if (array_key_exists($doctorName, $this->doctorIdByFullNameCache)) {
            return $this->doctorIdByFullNameCache[$doctorName];
        }

        $parts = preg_split('/\s+/', $doctorName);
        $lastName = $parts[0] ?? null;
        $firstName = $parts[1] ?? null;
        $secondName = $parts[2] ?? null;

        if (! $lastName || ! $firstName) {
            return $this->doctorIdByFullNameCache[$doctorName] = null;
        }

        $query = Doctor::query()
            ->where('last_name', $lastName)
            ->where('first_name', $firstName);

        if ($secondName) {
            $query->where('second_name', $secondName);
        } else {
            $query->where(function ($q) {
                $q->whereNull('second_name')
                    ->orWhere('second_name', '');
            });
        }

        $doctorIdByName = $query->value('id');

        return $this->doctorIdByFullNameCache[$doctorName] = $doctorIdByName ? (int) $doctorIdByName : null;
    }

    /**
     * Собираем данные слота из extendedProps для форм создания/просмотра.
     */
    protected function buildOnecSlotData(array $extendedProps, bool $forView = false): array
    {
        $slotStart = $this->normalizeEventTime($extendedProps['slot_start'] ?? null);
        $doctorId = $this->resolveOnecDoctorId($extendedProps);
        $doctorName = $extendedProps['doctor_name'] ?? null;

        if (! $doctorName && $doctorId) {
            $doctorName = $this->resolveDoctorNameById((int) $doctorId);
        }

        return [
            'city_id' => $extendedProps['city_id'] ?? null,
            'city_name' => $extendedProps['city_name'] ?? null,
            'clinic_id' => $extendedProps['clinic_id'] ?? null,
            'clinic_name' => $extendedProps['clinic_name'] ?? null,
            'branch_id' => $extendedProps['branch_id'] ?? null,
            'branch_name' => $extendedProps['branch_name'] ?? null,
            'cabinet_id' => $extendedProps['cabinet_id'] ?? null,
            'cabinet_name' => $extendedProps['cabinet_name'] ?? null,
            'doctor_id' => $doctorId,
            'doctor_name' => $doctorName,
            'appointment_datetime' => $slotStart,
            'onec_slot_id' => $extendedProps['onec_slot_id'] ?? null,
            'source' => 'onec',
            'send_to_1c' => true,
            'full_name' => $forView ? ($extendedProps['patient_name'] ?? 'Запись из 1С') : '',
            'phone' => $forView ? ($extendedProps['patient_phone'] ?? '') : '',
            'full_name_parent' => '',
            'birth_date' => '',
            'promo_code' => '',
            'appointment_status' => $forView ? 'Занят (1С)' : null,
        ];
    }
}
