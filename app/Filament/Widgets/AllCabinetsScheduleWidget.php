<?php

namespace App\Filament\Widgets;

use App\Models\DoctorShift;
use App\Models\Doctor;
use App\Models\Cabinet;
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
     * Конфигурация календаря
     * Настройки отображения и поведения календаря для всех кабинетов
     */
    public function config(): array
    {
        $user = auth()->user();
        $isDoctor = $user && $user->isDoctor();
        
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
            'slotDuration' => '00:30:00',     // Длительность слота (30 минут)
            'snapDuration' => '00:15:00',     // Шаг привязки времени (15 минут)
        ];
    }

    /**
     * Получение событий для отображения в календаре
     * Загружает смены врачей по всем кабинетам с фильтрацией по ролям
     */
    public function fetchEvents(array $fetchInfo): array
    {
        $user = auth()->user();
        
        // Базовый запрос смен в указанном диапазоне дат
        $query = DoctorShift::query()
            ->whereBetween('start_time', [$fetchInfo['start'], $fetchInfo['end']])
            ->with(['doctor', 'cabinet.branch']);
        
        // Фильтрация по ролям
        if ($user->isPartner()) {
            // Партнер видит только смены в кабинетах своих клиник
            $query->whereHas('cabinet.branch', function($q) use ($user) {
                $q->where('clinic_id', $user->clinic_id);
            });
        } elseif ($user->isDoctor()) {
            // Врач видит только свои смены
            $query->where('doctor_id', $user->doctor_id);
        }
        // super_admin видит все смены
        
        // Преобразуем смены в формат FullCalendar
        return $query->get()
            ->map(function (DoctorShift $shift) {
                return [
                    'id' => $shift->id,
                    'title' => ($shift->doctor->full_name ?? 'Врач не назначен') . ' - ' . ($shift->cabinet->name ?? 'Кабинет не указан'),
                    'start' => $shift->start_time,
                    'end' => $shift->end_time,
                    'backgroundColor' => $this->getShiftColor($shift),
                    'borderColor' => $this->getShiftColor($shift),
                    'extendedProps' => [
                        'doctor_id' => $shift->doctor_id,
                        'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен',
                        'cabinet_id' => $shift->cabinet_id,
                        'cabinet_name' => $shift->cabinet->name ?? 'Кабинет не указан',
                        'branch_name' => $shift->cabinet->branch->name ?? 'Филиал не указан',
                        'slot_duration' => $shift->slot_duration,
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
            
            TextInput::make('slot_duration')
                ->label('Длительность слота (минуты)')
                ->numeric()
                ->default(30)
                ->required()
                ->minValue(15)
                ->maxValue(120),
            
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
        // Цвета для разных кабинетов
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
                        'slot_duration' => $this->record->slot_duration,
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
        
        // Врач не может создавать смены
        if ($user->isDoctor()) {
            return [];
        }
        
        return [
            \Saade\FilamentFullCalendar\Actions\CreateAction::make()
                ->mountUsing(function (\Filament\Forms\Form $form, array $arguments) {
                    $form->fill([
                        'start_time' => $arguments['start'] ?? null,
                        'end_time' => $arguments['end'] ?? null,
                        'slot_duration' => 30,
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
                }),
        ];
    }
}
