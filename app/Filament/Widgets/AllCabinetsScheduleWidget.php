<?php

namespace App\Filament\Widgets;

use App\Models\DoctorShift;
use App\Models\Doctor;
use App\Models\Cabinet;
use App\Services\CalendarFilterService;
use App\Services\TimezoneService;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;

/**
 * Виджет календаря расписания для всех кабинетов
 * 
 * Отображает календарь с расписанием врачей по всем кабинетам.
 * Позволяет создавать, редактировать и удалять смены врачей.
 * Включает фильтрацию по ролям пользователей и выбор кабинета при создании смены.
 */
class AllCabinetsScheduleWidget extends FullCalendarWidget
{
    // Модель для работы с данными
    public \Illuminate\Database\Eloquent\Model | string | null $model = DoctorShift::class;
    
    /**
     * Фильтры для календаря смен врачей
     * Сохраняют состояние фильтрации по врачам и датам
     */
    public array $filters = [
        'doctor_ids' => [],
        'date_from' => null,
        'date_to' => null,
    ];
    
    /**
     * Слушатели событий для обновления календаря
     */
    protected $listeners = ['refetchEvents', 'shiftFiltersUpdated', '$refresh'];
    
    /**
     * Сервисы для работы с фильтрами и часовыми поясами
     */
    protected ?CalendarFilterService $filterService = null;
    protected ?TimezoneService $timezoneService = null;
    
    public function getFilterService(): CalendarFilterService
    {
        if ($this->filterService === null) {
            $this->filterService = app(CalendarFilterService::class);
        }
        return $this->filterService;
    }
    
    public function getTimezoneService(): TimezoneService
    {
        if ($this->timezoneService === null) {
            $this->timezoneService = app(TimezoneService::class);
        }
        return $this->timezoneService;
    }

    /**
     * Обработчик обновления фильтров
     */
    public function shiftFiltersUpdated(array $filters): void
    {
        $this->filters = $filters;
        $this->refreshRecords();
    }


    /**
     * Конфигурация календаря
     * Настройки отображения и поведения календаря для всех кабинетов
     */
    public function config(): array
    {
        $user = auth()->user();
        $isDoctor = $user && $user->isDoctor();
        
        // Для общего календаря используем минимальную длительность слота (15 минут)
        // чтобы показать все возможные слоты
        return [
            'firstDay' => 1, // Понедельник - первый день недели
            'headerToolbar' => [
                'left' => 'prev,next today',  // Навигация по датам
                'center' => 'title',          // Заголовок с текущей датой
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'  // Переключатели видов
            ],
            'initialView' => 'timeGridWeek',  // Начальный вид - неделя по времени
            'navLinks' => true,               // Кликабельные даты
            'editable' => !$isDoctor,         // Врач не может редактировать
            'selectable' => !$isDoctor,       // Врач не может выбирать время
            'selectMirror' => !$isDoctor,     // Отображение выбранного времени
            'dayMaxEvents' => true,           // Показывать "+X еще" при переполнении
            'weekends' => true,               // Показывать выходные
            'locale' => 'ru',                 // Русская локализация
            'buttonText' => [
                'today' => 'Сегодня',
                'month' => 'Месяц',
                'week' => 'Неделя',
                'day' => 'День',
                'list' => 'Список'
            ],
            'allDaySlot' => false,            // Не показывать слот "Весь день"
            'slotMinTime' => '08:00:00',      // Минимальное время отображения
            'slotMaxTime' => '20:00:00',      // Максимальное время отображения
            'slotDuration' => '00:15:00',     // Минимальная длительность слота для общего календаря
            'snapDuration' => '00:15:00',     // Шаг привязки времени (15 минут)
            'slotLabelFormat' => [            // Формат отображения времени в слотах
                'hour' => '2-digit',
                'minute' => '2-digit',
                'hour12' => false,            // 24-часовой формат
            ],
            'eventDidMount' => 'function(info) {
                // Добавляем стили для прошедших смен
                if (info.event.extendedProps.is_past) {
                    info.el.style.opacity = "0.6";
                    info.el.style.filter = "grayscale(50%)";
                    info.el.title = "Прошедшая смена";
                } else {
                    info.el.title = "Активная смена";
                }
            }',
        ];
    }

    /**
     * Получение событий для отображения в календаре
     * Загружает смены врачей по всем кабинетам с фильтрацией по ролям и пользовательским фильтрам
     */
    public function fetchEvents(array $fetchInfo): array
    {
        $user = auth()->user();
        
        // Базовый запрос смен в указанном диапазоне дат
        $query = DoctorShift::query()
            ->whereBetween('start_time', [$fetchInfo['start'], $fetchInfo['end']])
            ->with(['doctor', 'cabinet.branch']);
        
        // Применяем пользовательские фильтры
        $this->getFilterService()->applyShiftFilters($query, $this->filters, $user);
        
        // Преобразуем смены в формат FullCalendar
        return $query->get()
            ->map(function (DoctorShift $shift) {
                $shiftStart = \Carbon\Carbon::parse($shift->start_time);
                $shiftEnd = \Carbon\Carbon::parse($shift->end_time);
                
                // Получаем часовой пояс города филиала
                $cityId = $shift->cabinet->branch->city_id;
                $cityTimezone = $this->getTimezoneService()->getCityTimezone($cityId);
                
                // Конвертируем время смены в часовой пояс города
                $shiftStartInCity = $shiftStart->setTimezone($cityTimezone);
                
                // Проверяем, прошла ли дата (не время!) в часовом поясе города
                $nowInCity = $this->getTimezoneService()->nowInCityTimezone($cityId);
                $isPast = $shiftStartInCity->format('Y-m-d') < $nowInCity->format('Y-m-d');
                
                return [
                    'id' => $shift->id,
                    'title' => ($shift->doctor->full_name ?? 'Врач не назначен') . ' - ' . ($shift->cabinet->name ?? 'Кабинет не указан'),
                    'start' => $shift->start_time,
                    'end' => $shift->end_time,
                    'backgroundColor' => $this->getShiftColor($shift),
                    'borderColor' => $this->getShiftColor($shift),
                    'classNames' => $isPast ? ['past-shift'] : ['active-shift'],
                    'extendedProps' => [
                        'doctor_id' => $shift->doctor_id,
                        'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен',
                        'cabinet_id' => $shift->cabinet_id,
                        'cabinet_name' => $shift->cabinet->name ?? 'Кабинет не указан',
                        'branch_name' => $shift->cabinet->branch->name ?? 'Филиал не указан',
                        'city_id' => $cityId,
                        'city_timezone' => $cityTimezone,
                        'is_past' => $isPast,
                        'shift_start_city_time' => $shiftStartInCity->format('Y-m-d H:i:s'),
                    ]
                ];
            })
            ->toArray();
    }

    public function getFormSchema(): array
    {
        return [
            Select::make('cabinet_id')
                ->label('Кабинет')
                ->required()
                ->searchable()
                ->options(function () {
                    $user = auth()->user();
                    
                    $query = Cabinet::with('branch');
                    
                    // Фильтрация по ролям
                    if ($user->isPartner()) {
                        $query->whereHas('branch', function($q) use ($user) {
                            $q->where('clinic_id', $user->clinic_id);
                        });
                    } elseif ($user->isDoctor()) {
                        $query->whereHas('branch.doctors', function($q) use ($user) {
                            $q->where('doctor_id', $user->doctor_id);
                        });
                    }
                    // super_admin видит все
                    
                    return $query->get()->mapWithKeys(function ($cabinet) {
                        return [$cabinet->id => $cabinet->branch->name . ' - ' . $cabinet->name];
                    })->toArray();
                }),
            
            Select::make('doctor_id')
                ->label('Врач')
                ->required()
                ->searchable()
                ->options(function (Get $get) {
                    $cabinetId = $get('cabinet_id');
                    if (!$cabinetId) {
                        return [];
                    }
                    
                    $cabinet = Cabinet::with('branch.doctors')->find($cabinetId);
                    
                    if (!$cabinet || !$cabinet->branch) {
                        return [];
                    }
                    
                    return $cabinet->branch->doctors->mapWithKeys(function ($doctor) {
                        return [$doctor->id => $doctor->full_name];
                    })->toArray();
                }),
            

            
            DateTimePicker::make('start_time')
                ->label('Начало смены')
                ->required()
                ->seconds(false)
                ->minutesStep(15),
            
            DateTimePicker::make('end_time')
                ->label('Конец смены')
                ->required()
                ->seconds(false)
                ->minutesStep(15),
        ];
    }

    protected function getShiftColor(DoctorShift $shift): string
    {
        // Проверяем, прошла ли дата смены
        $shiftStart = \Carbon\Carbon::parse($shift->start_time);
        $cityId = $shift->cabinet->branch->city_id;
        $nowInCity = $this->getTimezoneService()->nowInCityTimezone($cityId);
        
        if ($shiftStart->format('Y-m-d') < $nowInCity->format('Y-m-d')) {
            // Для прошедших смен используем серые цвета
            $pastColors = [
                '#9CA3AF', // серый
                '#6B7280', // темно-серый
                '#4B5563', // еще темнее
                '#374151', // очень темный
            ];
            
            $cabinetId = $shift->cabinet_id ?? 0;
            return $pastColors[$cabinetId % count($pastColors)];
        }
        
        // Цвета для активных смен
        $colors = [
            '#3B82F6', // синий
            '#10B981', // зеленый
            '#F59E0B', // желтый
            '#EF4444', // красный
            '#8B5CF6', // фиолетовый
            '#06B6D4', // голубой
            '#84CC16', // лайм
            '#F97316', // оранжевый
        ];
        
        $cabinetId = $shift->cabinet_id ?? 0;
        return $colors[$cabinetId % count($colors)];
    }

    protected function modalActions(): array
    {
        $user = auth()->user();
        
        // Врач может только просматривать
        if ($user->isDoctor()) {
            return [];
        }
        
        return [
            \Saade\FilamentFullCalendar\Actions\EditAction::make()
                ->mountUsing(function (\Filament\Forms\Form $form, array $arguments) {
                    $form->fill([
                        'cabinet_id' => $this->record->cabinet_id,
                        'doctor_id' => $this->record->doctor_id,
                        'start_time' => $arguments['event']['start'] ?? $this->record->start_time,
                        'end_time' => $arguments['event']['end'] ?? $this->record->end_time,
                    ]);
                })
                ->action(function (array $data) {
                    $this->record->update($data);
                    
                    Notification::make()
                        ->title('Смена обновлена')
                        ->body('Смена врача успешно обновлена')
                        ->success()
                        ->send();
                        
                    $this->refreshRecords();
                }),
                
            \Saade\FilamentFullCalendar\Actions\DeleteAction::make()
                ->action(function () {
                    $this->record->delete();
                    
                    Notification::make()
                        ->title('Смена удалена')
                        ->body('Смена врача удалена из расписания')
                        ->success()
                        ->send();
                        
                    $this->refreshRecords();
                }),
        ];
    }

    protected function headerActions(): array
    {
        $user = auth()->user();
        $actions = [];
        
        // Добавляем кнопку фильтров
        $actions[] = \Filament\Actions\Action::make('filters')
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
                            
                        \Filament\Forms\Components\Select::make('doctor_ids')
                            ->label('Врачи')
                            ->multiple()
                            ->searchable()
                            ->options(fn() => $this->getFilterService()->getAvailableDoctorsForShifts(auth()->user())),
                    ]),
            ])
            ->fillForm($this->filters)
            ->action(function (array $data) {
                $this->filters = $data;
                $this->refreshRecords();
                
                Notification::make()
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
                            'doctor_ids' => [],
                            'date_from' => null,
                            'date_to' => null,
                        ];
                        
                        $this->refreshRecords();
                        
                        Notification::make()
                            ->title('Фильтры очищены')
                            ->success()
                            ->send();
                    })
            ])
            ->closeModalByClickingAway(false)
            ->modalSubmitActionLabel('Применить')
            ->modalCancelActionLabel('Отмена');
        
        // Врач не может создавать смены
        if (!$user->isDoctor()) {
            $actions[] = \Saade\FilamentFullCalendar\Actions\CreateAction::make()
                ->mountUsing(function (\Filament\Forms\Form $form, array $arguments) {
                    $form->fill([
                        'start_time' => $arguments['start'] ?? null,
                        'end_time' => $arguments['end'] ?? null,
                    ]);
                })
                ->action(function (array $data) {
                    DoctorShift::create($data);
                    
                    Notification::make()
                        ->title('Смена создана')
                        ->body('Смена врача успешно добавлена в расписание')
                        ->success()
                        ->send();
                        
                    $this->refreshRecords();
                });
        }
        
        return $actions;
    }
}
