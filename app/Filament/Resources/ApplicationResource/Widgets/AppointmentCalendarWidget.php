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
     * Слушатели событий для обновления календаря
     */
    protected $listeners = ['refetchEvents'];

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
        $events = [];

        // Базовый запрос смен в указанном диапазоне
        // Загружаем связанные данные для оптимизации запросов
        $shiftsQuery = DoctorShift::query()
            ->whereBetween('start_time', [$fetchInfo['start'], $fetchInfo['end']])
            ->with(['doctor', 'cabinet.branch.clinic', 'cabinet.branch.city']);

        // Фильтрация по ролям пользователя
        if ($user->isPartner()) {
            // Партнер видит только смены в кабинетах своих клиник
            $shiftsQuery->whereHas('cabinet.branch', function($q) use ($user) {
                $q->where('clinic_id', $user->clinic_id);
            });
        } elseif ($user->isDoctor()) {
            // Врач видит только смены где он назначен врачом
            $shiftsQuery->where('doctor_id', $user->doctor_id);
        }
        // super_admin видит все смены без ограничений

        $shifts = $shiftsQuery->get();

        // Обрабатываем каждую смену и создаем события для календаря
        foreach ($shifts as $shift) {
            // Получаем длительность слота для этой смены (может быть разной для разных смен)
            $slotDuration = $shift->getEffectiveSlotDuration();
            
            // Генерируем временные слоты для смены (например, каждые 15 минут)
            $slots = $shift->getTimeSlots();
            
            // Создаем событие для каждого временного слота
            foreach ($slots as $slot) {
                // Проверяем, есть ли уже заявка в этом слоте
                $isOccupied = $this->isSlotOccupied($shift->cabinet_id, $slot['start']);
                
                // Если слот занят, получаем информацию о заявке
                $application = null;
                if ($isOccupied) {
                    $applicationQuery = Application::query()
                        ->where('cabinet_id', $shift->cabinet_id)
                        ->where('appointment_datetime', $slot['start']);
                    
                    // Дополнительная фильтрация заявок по ролям пользователя
                    if ($user->isPartner()) {
                        // Партнер видит только заявки в своих клиниках
                        $applicationQuery->where('clinic_id', $user->clinic_id);
                    } elseif ($user->isDoctor()) {
                        // Врач видит только заявки где он назначен врачом
                        $applicationQuery->where('doctor_id', $user->doctor_id);
                    }
                    
                    $application = $applicationQuery->first();
                }
                
                // Формируем событие для календаря с полной информацией
                $events[] = [
                    'id' => 'slot_' . $shift->id . '_' . $slot['start']->format('Y-m-d_H-i'), // Уникальный ID слота
                    'title' => $isOccupied ? ($application ? $application->full_name : 'Занят') : 'Свободен', // Название события
                    'start' => $slot['start'], // Время начала слота
                    'end' => $slot['end'], // Время окончания слота
                    'backgroundColor' => $isOccupied ? '#dc2626' : '#10b981', // Красный для занятых, зеленый для свободных
                    'borderColor' => $isOccupied ? '#dc2626' : '#10b981', // Цвет границы
                    'extendedProps' => [
                        'shift_id' => $shift->id, // ID смены врача
                        'cabinet_id' => $shift->cabinet_id, // ID кабинета
                        'doctor_id' => $shift->doctor_id, // ID врача
                        'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен', // ФИО врача
                        'cabinet_name' => $shift->cabinet->name ?? 'Кабинет не указан', // Название кабинета
                        'branch_name' => $shift->cabinet->branch->name ?? 'Филиал не указан', // Название филиала
                        'clinic_name' => $shift->cabinet->branch->clinic->name ?? 'Клиника не указана', // Название клиники
                        'is_occupied' => $isOccupied, // Флаг занятости слота
                        'slot_start' => $slot['start'], // Время начала слота
                        'slot_end' => $slot['end'], // Время окончания слота
                        'application_id' => $application ? $application->id : null, // ID заявки (если есть)
                    ]
                ];
            }
        }

        return $events;
    }

    /**
     * Проверить, занят ли временной слот
     * 
     * Проверяет наличие заявки в указанном кабинете в указанное время.
     * Используется для определения цвета отображения слота в календаре.
     * 
     * @param int $cabinetId ID кабинета
     * @param Carbon $slotStart Время начала слота
     * @return bool true если слот занят, false если свободен
     */
    private function isSlotOccupied(int $cabinetId, Carbon $slotStart): bool
    {
        // Проверяем, есть ли заявка в этом слоте (без фильтрации по ролям)
        // Эта проверка используется только для определения цвета отображения
        return Application::query()
            ->where('cabinet_id', $cabinetId)
            ->where('appointment_datetime', $slotStart)
            ->exists();
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
     * 
     * @param array $data Данные события календаря
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
        
        // Находим заявку по кабинету и времени приема
        $slotStart = $extendedProps['slot_start'];
        if (is_string($slotStart)) {
            $slotStart = \Carbon\Carbon::parse($slotStart); // Преобразуем строку в объект Carbon
        }
        
        // Строим запрос для поиска заявки
        // Загружаем связанные данные для отображения в форме
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

        // Заполняем данные для формы просмотра/редактирования
        // Включаем как служебную информацию, так и данные пациента
        $this->slotData = [
            'application_id' => $application->id, // ID заявки для обновления
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
            'full_name' => $application->full_name, // ФИО ребенка
            'phone' => $application->phone, // Телефон
            'full_name_parent' => $application->full_name_parent, // ФИО родителя
            'birth_date' => $application->birth_date, // Дата рождения
            'promo_code' => $application->promo_code, // Промокод
        ];

        // Устанавливаем запись для действий
        $this->record = $application;
        
        // Открываем форму для всех ролей, но с разными правами
        // if ($user->isDoctor()) {
        //     // Для врача показываем форму только для просмотра
        //     $this->mountAction('view');
        // } else {
        //     // Открываем форму редактирования для партнеров и админов
        //     $this->mountAction('view');
        // }

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
                
            \Filament\Actions\Action::make('editAppointment')
                ->label('Редактировать заявку')
                ->icon('heroicon-o-pencil')
                ->visible(fn() => !auth()->user()->isDoctor())
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
                        
                        $application->update($applicationData);
                    } else {
                        // Для админов обновляем заявку
                        $application = Application::find($this->slotData['application_id'] ?? null);
                        if ($application) {
                            $application->update($applicationData);
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
}
