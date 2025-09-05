<?php

namespace App\Filament\Widgets;

use App\Models\DoctorShift;
use App\Models\Doctor;
use App\Models\Cabinet;
use App\Services\TimezoneService;
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
     * Сервис для работы с часовыми поясами
     */
    protected ?TimezoneService $timezoneService = null;

    public function getTimezoneService(): TimezoneService
    {
        if ($this->timezoneService === null) {
            $this->timezoneService = app(TimezoneService::class);
        }
        return $this->timezoneService;
    }

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
     * Получение длительности слота для кабинета
     * Возвращает настройку из филиала в формате для FullCalendar
     */
    protected function getCabinetSlotDuration(): string
    {
        $cabinetId = $this->getCabinetIdFromContext();

        if (!$cabinetId) {
            return '00:30:00'; // По умолчанию 30 минут
        }

        $cabinet = \App\Models\Cabinet::with('branch')->find($cabinetId);

        if (!$cabinet || !$cabinet->branch) {
            return '00:30:00'; // По умолчанию 30 минут
        }

        $duration = $cabinet->branch->getEffectiveSlotDuration();

        // Преобразуем минуты в формат HH:MM:SS
        $hours = intval($duration / 60);
        $minutes = $duration % 60;

        return sprintf('%02d:%02d:00', $hours, $minutes);
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

        // Получаем длительность слота для кабинета
        $slotDuration = $this->getCabinetSlotDuration();

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
            'slotDuration' => $slotDuration,  // Длительность слота из настроек филиала
            'snapDuration' => $slotDuration,  // Шаг привязки времени равен длительности слота
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
                    info.el.title = "Прошедшая смена: " + info.event.title;
                } else {
                    info.el.title = "Активная смена: " + info.event.title;
                }
            }',
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
                $shiftStart = \Carbon\Carbon::parse($shift->start_time);
                $shiftEnd = \Carbon\Carbon::parse($shift->end_time);

                // Получаем часовой пояс города филиала
                $cabinet = \App\Models\Cabinet::with('branch')->find($shift->cabinet_id);
                $cityId = $cabinet->branch->city_id;
                $cityTimezone = $this->getTimezoneService()->getCityTimezone($cityId);

                // Конвертируем время смены в часовой пояс города
                $shiftStartInCity = $shiftStart->setTimezone($cityTimezone);

                // Проверяем, прошла ли дата (не время!) в часовом поясе города
                $nowInCity = $this->getTimezoneService()->nowInCityTimezone($cityId);
                $isPast = $shiftStartInCity->format('Y-m-d') < $nowInCity->format('Y-m-d');

                return [
                    'id' => $shift->id,
                    'title' => $shift->doctor->full_name ?? 'Врач не назначен',
                    'start' => $shift->start_time,
                    'end' => $shift->end_time,
                    'backgroundColor' => $this->getShiftColor($shift),
                    'borderColor' => $this->getShiftColor($shift),
                    'classNames' => $isPast ? ['past-shift'] : ['active-shift'],
                    'extendedProps' => [
                        'doctor_id' => $shift->doctor_id,
                        'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен',
                        'city_id' => $cityId,
                        'city_timezone' => $cityTimezone,
                        'is_past' => $isPast,
                        'shift_start_city_time' => $shiftStartInCity->format('Y-m-d H:i:s'),
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
     * Прошедшие смены отображаются серыми
     */
    protected function getShiftColor(DoctorShift $shift): string
    {
        // Проверяем, прошла ли дата смены
        $shiftStart = \Carbon\Carbon::parse($shift->start_time);
        $cabinet = \App\Models\Cabinet::with('branch')->find($shift->cabinet_id);
        $cityId = $cabinet->branch->city_id;
        $nowInCity = $this->getTimezoneService()->nowInCityTimezone($cityId);

        if ($shiftStart->format('Y-m-d') < $nowInCity->format('Y-m-d')) {
            // Для прошедших смен используем серые цвета
            $pastColors = [
                '#9CA3AF', // серый
                '#6B7280', // темно-серый
                '#4B5563', // еще темнее
                '#374151', // очень темный
            ];

            $doctorId = $shift->doctor_id ?? 0;
            return $pastColors[$doctorId % count($pastColors)];
        }

        // Палитра цветов для активных смен разных врачей
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
