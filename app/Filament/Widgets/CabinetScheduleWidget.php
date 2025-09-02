<?php

namespace App\Filament\Widgets;

use App\Models\DoctorShift;
use App\Models\Doctor;
use App\Models\Cabinet;
use Filament\Widgets\Widget;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Grid;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;

/**
 * Виджет календаря расписания для конкретного кабинета
 * 
 * Отображает календарь с расписанием врачей для выбранного кабинета.
 * Позволяет создавать, редактировать и удалять смены врачей.
 * Включает фильтрацию по ролям пользователей и автоматическое определение ID кабинета.
 */
class CabinetScheduleWidget extends FullCalendarWidget
{
    // Модель для работы с данными
    public \Illuminate\Database\Eloquent\Model | string | null $model = DoctorShift::class;
    
    // ID кабинета для которого отображается расписание
    public ?int $cabinetId = null;
    
    /**
     * Получение ID кабинета
     */
    public function getCabinetId(): ?int
    {
        return $this->cabinetId;
    }
    
    /**
     * Установка ID кабинета
     */
    public function setCabinetId(?int $cabinetId): void
    {
        $this->cabinetId = $cabinetId;
    }
    
    /**
     * Инициализация при загрузке виджета
     * Получаем cabinet_id из параметров запроса
     */
    public function boot(): void
    {
        if (!$this->cabinetId) {
            $this->cabinetId = request()->route('record');
        }
    }
    
    /**
     * Инициализация при монтировании компонента
     * Устанавливаем cabinet_id при монтировании компонента
     */
    public function mount(): void
    {
        if (!$this->cabinetId) {
            $this->cabinetId = request()->route('record');
        }
    }
    
    /**
     * Получение ID кабинета из контекста
     * Пытается получить ID кабинета из различных источников
     */
    public function getCabinetIdFromContext(): ?int
    {
        // В первую очередь используем сохраненное значение
        if ($this->cabinetId) {
            return $this->cabinetId;
        }
        
        // Если не установлено, пытаемся получить из маршрута
        $record = request()->route('record');
        if ($record) {
            $this->cabinetId = (int) $record;
            return $this->cabinetId;
        }
        
        // Из Livewire компонента (если мы на странице кабинета)
        try {
            $livewire = app('livewire')->current();
            if ($livewire && method_exists($livewire, 'getRecord')) {
                $record = $livewire->getRecord();
                if ($record) {
                    $this->cabinetId = $record->id;
                    return $this->cabinetId;
                }
            }
        } catch (\Exception $e) {
            // Игнорируем ошибки при попытке получить record
        }
        
        return null;
    }
    
    /**
     * Проверка возможности отображения виджета
     * Показываем виджет только на страницах кабинетов
     */
    public static function canView(): bool
    {
        $route = request()->route();
        if (!$route) {
            return false;
        }
        
        $routeName = $route->getName();
        return str_contains($routeName, 'cabinets') && $route->parameter('record');
    }

    /**
     * Конфигурация календаря
     * Настройки отображения и поведения календаря
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
     * Загружает смены врачей для конкретного кабинета с фильтрацией по ролям
     */
    public function fetchEvents(array $fetchInfo): array
    {
        $cabinetId = $this->getCabinetIdFromContext();
        
        if (!$cabinetId) {
            return [];
        }

        $user = auth()->user();
        
        // Базовый запрос смен для кабинета в указанном диапазоне дат
        $query = DoctorShift::query()
            ->where('cabinet_id', $cabinetId)
            ->whereBetween('start_time', [$fetchInfo['start'], $fetchInfo['end']])
            ->with(['doctor']);
        
        // Дополнительная фильтрация по ролям
        if ($user->isDoctor()) {
            // Врач видит только свои смены
            $query->where('doctor_id', $user->doctor_id);
        } elseif ($user->isPartner()) {
            // Проверяем, что кабинет принадлежит клинике партнера
            $cabinet = \App\Models\Cabinet::with('branch')->find($cabinetId);
            if (!$cabinet || $cabinet->branch->clinic_id !== $user->clinic_id) {
                return [];
            }
        }
        // super_admin видит все смены
        
        // Преобразуем смены в формат FullCalendar
        return $query->get()
            ->map(function (DoctorShift $shift) {
                return [
                    'id' => $shift->id,
                    'title' => $shift->doctor->full_name ?? 'Врач не назначен',
                    'start' => $shift->start_time,
                    'end' => $shift->end_time,
                    'backgroundColor' => $this->getShiftColor($shift),
                    'borderColor' => $this->getShiftColor($shift),
                    'extendedProps' => [
                        'doctor_id' => $shift->doctor_id,
                        'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен',
                        'slot_duration' => $shift->slot_duration,
                    ]
                ];
            })
            ->toArray();
    }





    /**
     * Схема формы для создания/редактирования смены
     * Включает выбор врача, длительность слота и время смены
     */
    public function getFormSchema(): array
    {
        return [
            // Выбор врача из филиала кабинета
            Select::make('doctor_id')
                ->label('Врач')
                ->required()
                ->searchable()
                ->options(function () {
                    $cabinetId = $this->getCabinetIdFromContext();
                    
                    if (!$cabinetId) {
                        return [];
                    }
                    
                    $cabinet = \App\Models\Cabinet::with('branch.doctors')->find($cabinetId);
                    
                    if (!$cabinet || !$cabinet->branch) {
                        return [];
                    }
                    
                    // Показываем только врачей из филиала кабинета
                    return $cabinet->branch->doctors->mapWithKeys(function ($doctor) {
                        return [$doctor->id => $doctor->full_name];
                    })->toArray();
                }),
            
            // Длительность слота для записи пациентов
            TextInput::make('slot_duration')
                ->label('Длительность слота (минуты)')
                ->numeric()
                ->default(30)
                ->required()
                ->minValue(15)
                ->maxValue(120),
            
            // Время начала смены
            DateTimePicker::make('start_time')
                ->label('Начало смены')
                ->required()
                ->seconds(false)
                ->minutesStep(15),
            
            // Время окончания смены
            DateTimePicker::make('end_time')
                ->label('Конец смены')
                ->required()
                ->seconds(false)
                ->minutesStep(15),
        ];
    }



    /**
     * Получение цвета для смены врача
     * Каждый врач получает свой цвет на основе ID
     */
    protected function getShiftColor(DoctorShift $shift): string
    {
        // Палитра цветов для разных врачей
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
        
        $doctorId = $shift->doctor_id ?? 0;
        return $colors[$doctorId % count($colors)];
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
                
            \Filament\Actions\Action::make('duplicate')
                ->label('Дублировать')
                ->icon('heroicon-o-document-duplicate')
                ->color('info')
                ->action(function () {
                    $originalShift = $this->record;
                    
                    // Создаем копию смены на следующий день
                    $newStartTime = \Carbon\Carbon::parse($originalShift->start_time)->addDay();
                    $newEndTime = \Carbon\Carbon::parse($originalShift->end_time)->addDay();
                    
                    DoctorShift::create([
                        'doctor_id' => $originalShift->doctor_id,
                        'cabinet_id' => $originalShift->cabinet_id,
                        'start_time' => $newStartTime,
                        'end_time' => $newEndTime,
                        'slot_duration' => $originalShift->slot_duration,
                    ]);
                    
                    Notification::make()
                        ->title('Смена дублирована')
                        ->body('Смена врача скопирована на следующий день')
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
                    $cabinetId = $this->getCabinetIdFromContext();
                    
                    if (!$cabinetId) {
                        Notification::make()
                            ->title('Ошибка')
                            ->body('Не указан ID кабинета')
                            ->danger()
                            ->send();
                        return;
                    }
                    
                    $data['cabinet_id'] = $cabinetId;
                    
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
