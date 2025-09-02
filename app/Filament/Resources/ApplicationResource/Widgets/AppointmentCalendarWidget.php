<?php

namespace App\Filament\Resources\ApplicationResource\Widgets;

use App\Models\Application;
use App\Models\DoctorShift;
use App\Models\Cabinet;
use App\Models\Doctor;
use App\Models\Branch;
use App\Models\Clinic;
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
 * Отображает календарь с заявками на прием.
 * Позволяет создавать, редактировать и удалять записи с разграничением по ролям.
 */
class AppointmentCalendarWidget extends FullCalendarWidget
{
    public \Illuminate\Database\Eloquent\Model | string | null $model = Application::class;
    
    public array $slotData = [];
    
    protected $listeners = ['refetchEvents'];

    /**
     * Конфигурация календаря
     */
    public function config(): array
    {
        $user = auth()->user();
        $isDoctor = $user && $user->isDoctor();
        
        return [
            'firstDay' => 1, // Понедельник - первый день недели
            'headerToolbar' => [
                'left' => 'prev,next today',
                'center' => 'title',
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
            ],
            'initialView' => 'timeGridWeek',
            'navLinks' => true,
            'editable' => false, // Отключаем редактирование для всех
            'selectable' => false, // Отключаем выбор для всех
            'selectMirror' => false, // Отключаем отображение выбранного времени
            'dayMaxEvents' => true,
            'weekends' => true,
            'locale' => 'ru',
            'buttonText' => [
                'today' => 'Сегодня',
                'month' => 'Месяц',
                'week' => 'Неделя',
                'day' => 'День',
                'list' => 'Список'
            ],
            'allDaySlot' => false,
            'slotMinTime' => '08:00:00',
            'slotMaxTime' => '20:00:00',
            'slotDuration' => '00:15:00', // 15 минут для отображения всех возможных слотов
            'snapDuration' => '00:15:00',
            'slotLabelFormat' => [            // Формат отображения времени в слотах
                'hour' => '2-digit',
                'minute' => '2-digit',
                'hour12' => false,            // 24-часовой формат
            ],
        ];
    }

    /**
     * Получить события для календаря
     */
    public function fetchEvents(array $fetchInfo): array
    {
        $user = auth()->user();
        $events = [];

        // Базовый запрос смен в указанном диапазоне
        $shiftsQuery = DoctorShift::query()
            ->whereBetween('start_time', [$fetchInfo['start'], $fetchInfo['end']])
            ->with(['doctor', 'cabinet.branch.clinic', 'cabinet.branch.city']);

        // Фильтрация по ролям
        if ($user->isPartner()) {
            // Партнер видит только смены в кабинетах своих клиник
            $shiftsQuery->whereHas('cabinet.branch', function($q) use ($user) {
                $q->where('clinic_id', $user->clinic_id);
            });
        } elseif ($user->isDoctor()) {
            // Врач видит только смены где он назначен врачом
            $shiftsQuery->where('doctor_id', $user->doctor_id);
        }
        // super_admin видит все смены

        $shifts = $shiftsQuery->get();

        foreach ($shifts as $shift) {
            // Получаем длительность слота для этой смены
            $slotDuration = $shift->getEffectiveSlotDuration();
            
            // Генерируем слоты для смены
            $slots = $shift->getTimeSlots();
            
            foreach ($slots as $slot) {
                // Проверяем, занят ли слот
                $isOccupied = $this->isSlotOccupied($shift->cabinet_id, $slot['start']);
                
                // Получаем информацию о заявке, если слот занят
                $application = null;
                if ($isOccupied) {
                    $applicationQuery = Application::query()
                        ->where('cabinet_id', $shift->cabinet_id)
                        ->where('appointment_datetime', $slot['start']);
                    
                    // Дополнительная фильтрация заявок по ролям
                    if ($user->isPartner()) {
                        // Партнер видит только заявки в своих клиниках
                        $applicationQuery->where('clinic_id', $user->clinic_id);
                    } elseif ($user->isDoctor()) {
                        // Врач видит только заявки где он назначен врачом
                        $applicationQuery->where('doctor_id', $user->doctor_id);
                    }
                    
                    $application = $applicationQuery->first();
                }
                
                $events[] = [
                    'id' => 'slot_' . $shift->id . '_' . $slot['start']->format('Y-m-d_H-i'),
                    'title' => $isOccupied ? ($application ? $application->full_name : 'Занят') : 'Свободен',
                    'start' => $slot['start'],
                    'end' => $slot['end'],
                    'backgroundColor' => $isOccupied ? '#dc2626' : '#10b981', // Красный для занятых, зеленый для свободных
                    'borderColor' => $isOccupied ? '#dc2626' : '#10b981',
                    'extendedProps' => [
                        'shift_id' => $shift->id,
                        'cabinet_id' => $shift->cabinet_id,
                        'doctor_id' => $shift->doctor_id,
                        'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен',
                        'cabinet_name' => $shift->cabinet->name ?? 'Кабинет не указан',
                        'branch_name' => $shift->cabinet->branch->name ?? 'Филиал не указан',
                        'clinic_name' => $shift->cabinet->branch->clinic->name ?? 'Клиника не указана',
                        'is_occupied' => $isOccupied,
                        'slot_start' => $slot['start'],
                        'slot_end' => $slot['end'],
                        'application_id' => $application ? $application->id : null,
                    ]
                ];
            }
        }

        return $events;
    }

    /**
     * Проверить, занят ли слот
     */
    private function isSlotOccupied(int $cabinetId, Carbon $slotStart): bool
    {
        // Проверяем, есть ли вообще заявка в этом слоте (без фильтрации по ролям)
        return Application::query()
            ->where('cabinet_id', $cabinetId)
            ->where('appointment_datetime', $slotStart)
            ->exists();
    }

    /**
     * Схема формы для создания и редактирования заявок
     */
    public function getFormSchema(): array
    {
        return [
            Grid::make(2)
                ->schema([
                    Select::make('city_id')
                        ->label('Город')
                        ->required()
                        ->searchable()
                        ->options(function () {
                            return \App\Models\City::pluck('name', 'id')->toArray();
                        })
                        ->reactive()
                        ->disabled()
                        ->afterStateUpdated(fn (Set $set) => $set('clinic_id', null)),
                    
                    Select::make('clinic_id')
                        ->label('Клиника')
                        ->required()
                        ->searchable()
                        ->options(function (Get $get) {
                            $cityId = $get('city_id');
                            if (!$cityId) return [];
                            return \App\Models\Clinic::whereIn('id', function($q) use ($cityId) {
                                $q->select('clinic_id')->from('branches')->where('city_id', $cityId);
                            })->pluck('name', 'id')->toArray();
                        })
                        ->reactive()
                        ->disabled()
                        ->afterStateUpdated(fn (Set $set) => $set('branch_id', null)),
                    
                    Select::make('branch_id')
                        ->label('Филиал')
                        ->required()
                        ->searchable()
                        ->options(function (Get $get) {
                            $clinicId = $get('clinic_id');
                            if (!$clinicId) return [];
                            return \App\Models\Branch::where('clinic_id', $clinicId)->pluck('name', 'id')->toArray();
                        })
                        ->reactive()
                        ->disabled()
                        ->afterStateUpdated(fn (Set $set) => $set('cabinet_id', null)),
                    
                    Select::make('cabinet_id')
                        ->label('Кабинет')
                        ->required()
                        ->searchable()
                        ->options(function (Get $get) {
                            $branchId = $get('branch_id');
                            if (!$branchId) return [];
                            return \App\Models\Cabinet::where('branch_id', $branchId)->pluck('name', 'id')->toArray();
                        })
                        ->reactive()
                        ->disabled()
                        ->afterStateUpdated(fn (Set $set) => $set('doctor_id', null)),
                    
                    Select::make('doctor_id')
                        ->label('Врач')
                        ->required()
                        ->disabled()
                        ->searchable()
                        ->options(function (Get $get) {
                            $cabinetId = $get('cabinet_id');
                            if (!$cabinetId) return [];
                            $cabinet = \App\Models\Cabinet::with('branch.doctors')->find($cabinetId);
                            if (!$cabinet || !$cabinet->branch) return [];
                            return $cabinet->branch->doctors->pluck('full_name', 'id')->toArray();
                        }),
                    
                    DateTimePicker::make('appointment_datetime')
                        ->label('Дата и время приема')
                        ->required()
                        ->seconds(false)
                        ->minutesStep(15),
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
        ];
    }

    /**
     * Модальные действия для событий
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
     * Обработка клика по событию
     */
    public function onEventClick(array $data): void
    {
        $user = auth()->user();
        $event = $data;
        $extendedProps = $event['extendedProps'] ?? [];
        
        // Если слот занят - открываем форму просмотра/редактирования
        if (isset($extendedProps['is_occupied']) && $extendedProps['is_occupied']) {
            $this->onOccupiedSlotClick($extendedProps);
            return;
        }

        // Если слот свободен - открываем форму создания
        // Врач не может создавать заявки
        if ($user->isDoctor()) {
            Notification::make()
                ->title('Ограничение')
                ->body('Врачи не могут создавать заявки')
                ->warning()
                ->send();
            return;
        }

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

        // Сохраняем данные слота в свойстве виджета
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
            'appointment_datetime' => $extendedProps['slot_start'],
        ];

        // Открываем форму для создания записи
        $this->mountAction('createAppointment');
    }

    /**
     * Обработка клика по занятому слоту
     */
    public function onOccupiedSlotClick(array $data): void
    {
        $user = auth()->user();
        $extendedProps = $data;
        
        // Находим заявку по кабинету и времени
        $slotStart = $extendedProps['slot_start'];
        if (is_string($slotStart)) {
            $slotStart = \Carbon\Carbon::parse($slotStart);
        }
        
        $applicationQuery = Application::query()
            ->with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
            ->where('cabinet_id', $extendedProps['cabinet_id'])
            ->where('appointment_datetime', $slotStart);
        
        // Фильтрация по ролям
        if ($user->isPartner()) {
            // Партнер видит только заявки в своих клиниках
            $applicationQuery->where('clinic_id', $user->clinic_id);
        } elseif ($user->isDoctor()) {
            // Врач видит только заявки где он назначен врачом
            $applicationQuery->where('doctor_id', $user->doctor_id);
        }
        
        $application = $applicationQuery->first();

        if (!$application) {
                    Notification::make()
            ->title('Ошибка')
            ->body('Заявка не найдена')
            ->danger()
            ->send();
        return;
    }

        // Заполняем данные для формы
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
        
        // Открываем форму для всех ролей, но с разными правами
        if ($user->isDoctor()) {
            // Для врача показываем форму только для просмотра
            $this->mountAction('view');
        } else {
            // Открываем форму редактирования для партнеров и админов
            $this->mountAction('editAppointment');
        }
    }

    /**
     * Действия в заголовке виджета
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
            \Filament\Actions\Action::make('createAppointment')
                ->label('Создать заявку')
                ->icon('heroicon-o-plus')
                ->form($this->getFormSchema())
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
                    
                    // Проверяем права доступа для партнеров
                    if ($user->isPartner()) {
                        // Проверяем, что создаваемая заявка относится к клинике партнера
                        if ($data['clinic_id'] !== $user->clinic_id) {
                            Notification::make()
                                ->title('Ошибка доступа')
                                ->body('Вы можете создавать заявки только в своей клинике')
                                ->danger()
                                ->send();
                            return;
                        }
                    }
                    
                    Application::create($data);
                    
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
                
            \Filament\Actions\Action::make('editAppointment')
                ->label('Редактировать заявку')
                ->icon('heroicon-o-pencil')
                ->visible(fn() => !auth()->user()->isDoctor())
                ->form($this->getFormSchema())
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
                            'full_name' => $this->slotData['full_name'] ?? '',
                            'phone' => $this->slotData['phone'] ?? '',
                            'full_name_parent' => $this->slotData['full_name_parent'] ?? '',
                            'birth_date' => $this->slotData['birth_date'] ?? '',
                            'promo_code' => $this->slotData['promo_code'] ?? '',
                        ]);
                    }
                })
                ->action(function (array $data) {
                    $user = auth()->user();
                    
                    // Проверяем права доступа
                    if ($user->isPartner()) {
                        // Находим заявку для проверки прав
                        $application = Application::find($this->slotData['application_id'] ?? null);
                        if (!$application || $application->clinic_id !== $user->clinic_id) {
                            Notification::make()
                                ->title('Ошибка доступа')
                                ->body('Вы можете редактировать заявки только своей клиники')
                                ->danger()
                                ->send();
                            return;
                        }
                        
                        $application->update($data);
                    } else {
                        // Для админов обновляем заявку
                        $application = Application::find($this->slotData['application_id'] ?? null);
                        if ($application) {
                            $application->update($data);
                        }
                    }
                    
                    Notification::make()
                        ->title('Заявка обновлена')
                        ->body('Заявка успешно обновлена')
                        ->success()
                        ->send();
                        
                    $this->refreshRecords();
                }),
        ];
    }
    
    /**
     * Обработчик события обновления календаря
     */
    public function refetchEvents()
    {
        // Принудительно обновляем события календаря
        $this->dispatch('$refresh');
    }
}
