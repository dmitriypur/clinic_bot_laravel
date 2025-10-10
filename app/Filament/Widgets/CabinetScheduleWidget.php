<?php

namespace App\Filament\Widgets;

use App\Models\DoctorShift;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TimePicker;
use App\Services\MassShiftCreator;
use App\Services\ShiftService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Filament\Forms\Form;
use Filament\Actions\Action;

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

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (array_key_exists('start_time', $data)) {
            $data['start_time'] = $this->normalizeEventTime($data['start_time'], true);
        }

        if (array_key_exists('end_time', $data)) {
            $data['end_time'] = $this->normalizeEventTime($data['end_time'], true);
        }

        return $data;
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
            'slotMinTime' => '08:00:00',      // Минимальное время отображения (расширили для ранних смен)
            'slotMaxTime' => '20:00:00',      // Максимальное время отображения (расширили для поздних смен)
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
        $rangeStart = Carbon::parse($fetchInfo['start'])->setTimezone('UTC');
        $rangeEnd = Carbon::parse($fetchInfo['end'])->setTimezone('UTC');

        // Базовый запрос смен для кабинета в указанном диапазоне дат
        $query = DoctorShift::query()
            ->where('cabinet_id', $cabinetId)
            ->whereBetween('start_time', [$rangeStart, $rangeEnd])
            ->with(['doctor']);

        // Дополнительно загружаем смены для текущего дня, если они не попадают в диапазон
        $todayStartLocal = now()->startOfDay();
        $todayEndLocal = now()->endOfDay();
        $todayStart = $todayStartLocal->copy()->setTimezone('UTC');
        $todayEnd = $todayEndLocal->copy()->setTimezone('UTC');

        $todayQuery = DoctorShift::query()
            ->where('cabinet_id', $cabinetId)
            ->whereBetween('start_time', [$todayStart, $todayEnd])
            ->with(['doctor']);

        // Применяем ту же фильтрацию по ролям для смен сегодня
        if ($user->isDoctor()) {
            $todayQuery->where('doctor_id', $user->doctor_id);
        } elseif ($user->isPartner()) {
            $cabinet = \App\Models\Cabinet::with('branch')->find($cabinetId);
            if (!$cabinet || $cabinet->branch->clinic_id !== $user->clinic_id) {
                $todayQuery->whereRaw('1=0'); // Пустой результат
            }
        }

        // Объединяем результаты
        $allShifts = $query->get()->merge($todayQuery->get())->unique('id');

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
        return $allShifts
            ->map(function (DoctorShift $shift) {
                $appTimezone = Config::get('app.timezone', 'UTC');
                $shiftStart = Carbon::parse($shift->getRawOriginal('start_time'), 'UTC')->setTimezone($appTimezone);
                $shiftEnd = Carbon::parse($shift->getRawOriginal('end_time'), 'UTC')->setTimezone($appTimezone);

                // Проверяем, прошла ли дата
                $isPast = $shiftStart->isPast();

                return [
                    'id' => $shift->id,
                    'title' => $shift->doctor->full_name ?? 'Врач не назначен',
                    'start' => $shiftStart->toIso8601String(),
                    'end' => $shiftEnd->toIso8601String(),
                    'backgroundColor' => $this->getShiftColor($shift),
                    'borderColor' => $this->getShiftColor($shift),
                    'classNames' => $isPast ? ['past-shift'] : ['active-shift'],
                    'extendedProps' => [
                        'doctor_id' => $shift->doctor_id,
                        'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен',
                        'city_id' => $shift->cabinet->branch->city_id ?? null,
                        'is_past' => $isPast,
                        'shift_start_time' => $shiftStart->format('Y-m-d H:i:s'),
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

            Toggle::make('has_break')
                ->label('Есть перерыв')
                ->inline(false)
                ->live(),

            TimePicker::make('break_start_time')
                ->label('Начало перерыва')
                ->seconds(false)
                ->minutesStep(5)
                ->visible(fn (Get $get) => (bool) $get('has_break'))
                ->required(fn (Get $get) => (bool) $get('has_break')),

            TimePicker::make('break_end_time')
                ->label('Конец перерыва')
                ->seconds(false)
                ->minutesStep(5)
                ->visible(fn (Get $get) => (bool) $get('has_break'))
                ->required(fn (Get $get) => (bool) $get('has_break')),
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
        $appTimezone = Config::get('app.timezone', 'UTC');
        $shiftStart = Carbon::parse($shift->getRawOriginal('start_time'), 'UTC')->setTimezone($appTimezone);
        $now = now();

        if ($shiftStart->format('Y-m-d') < $now->format('Y-m-d')) {
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

        // Палитра цветов для активных смен разных врачей (текущий день и будущие дни)
        $colors = [
            '#3B82F6', // синий
            '#31c090', // зеленый
            '#F59E0B', // желтый
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
                ->mountUsing($this->buildMountCallback())
                ->action(function (array $data) {
                    /** @var ShiftService $shiftService */
                    $shiftService = app(ShiftService::class);
                    $hasBreak = (bool) ($data['has_break'] ?? false);
                    $start = $data['start_time'];
                    $end = $data['end_time'];

                    if ($start instanceof CarbonInterface) {
                        $start = $start->toDateTimeString();
                    }

                    if ($end instanceof CarbonInterface) {
                        $end = $end->toDateTimeString();
                    }

                    if ($hasBreak) {
                        $workdayStart = $this->extractTimeComponent($data['start_time']);
                        $workdayEnd = $this->extractTimeComponent($data['end_time']);

                        /** @var MassShiftCreator $creator */
                        $creator = app(MassShiftCreator::class);

                        DB::transaction(function () use ($creator, $data, $start, $end, $workdayStart, $workdayEnd) {
                            $this->record->delete();

                            $creator->createSeries([
                                'doctor_id' => $data['doctor_id'],
                                'cabinet_id' => $this->record->cabinet_id,
                                'start_time' => $start,
                                'end_time' => $end,
                                'slot_duration' => $data['slot_duration'] ?? null,
                                'has_break' => true,
                                'break_start_time' => $data['break_start_time'] ?? null,
                                'break_end_time' => $data['break_end_time'] ?? null,
                                'workday_start' => $workdayStart,
                                'workday_end' => $workdayEnd,
                            ]);
                        });

                        Notification::make()
                            ->title('Смена обновлена')
                            ->body('Перерыв добавлен, расписание обновлено')
                            ->success()
                            ->send();

                        $this->refreshRecords();

                        return;
                    }

                    $updatedShift = $shiftService->update($this->record, [
                        'doctor_id' => $data['doctor_id'],
                        'cabinet_id' => $this->record->cabinet_id,
                        'start_time' => $start,
                        'end_time' => $end,
                    ]);

                    Notification::make()
                        ->title('Смена обновлена')
                        ->body('Смена врача успешно обновлена')
                        ->success()
                        ->send();

                    $this->refreshRecords();
                }),

            \Saade\FilamentFullCalendar\Actions\DeleteAction::make()
                ->mountUsing($this->buildMountCallback())
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
                ->mountUsing($this->buildMountCallback())
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

    protected function viewAction(): Action
    {
        return parent::viewAction()
            ->mountUsing($this->buildMountCallback());
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

                    /** @var MassShiftCreator $creator */
                    $creator = app(MassShiftCreator::class);
                    $workdayStart = $this->extractTimeComponent($data['start_time']);
                    $workdayEnd = $this->extractTimeComponent($data['end_time']);

                    try {
                        $created = $creator->createSeries([
                            'doctor_id' => $data['doctor_id'],
                            'cabinet_id' => $cabinetId,
                            'start_time' => $data['start_time'],
                            'end_time' => $data['end_time'],
                            'slot_duration' => $data['slot_duration'] ?? null,
                            'has_break' => (bool) ($data['has_break'] ?? false),
                            'break_start_time' => $data['break_start_time'] ?? null,
                            'break_end_time' => $data['break_end_time'] ?? null,
                            'workday_start' => $workdayStart,
                            'workday_end' => $workdayEnd,
                        ]);
                    } catch (ValidationException $exception) {
                        $messages = collect($exception->errors())
                            ->flatten()
                            ->implode("\n");

                        Notification::make()
                            ->title('Не удалось создать смены')
                            ->body($messages ?: 'Проверьте введенные данные и попробуйте снова.')
                            ->danger()
                            ->send();

                        return;
                    } catch (\Throwable $throwable) {
                        Notification::make()
                            ->title('Не удалось создать смены')
                            ->body('Произошла непредвиденная ошибка. ' . $throwable->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    $createdCount = $created instanceof Collection ? $created->count() : (is_array($created) ? count($created) : 1);
                    $title = $createdCount > 1
                        ? "Создано смен: {$createdCount}"
                        : 'Смена создана';

                    Notification::make()
                        ->title($title)
                        ->body('Смены врача успешно добавлены в расписание')
                        ->success()
                        ->send();

                    $this->refreshRecords();
                }),
        ];
    }

    protected function normalizeEventTime(mixed $value, bool $fromDatabase = false): ?Carbon
    {
        if (!$value) {
            return null;
        }

        $appTimezone = Config::get('app.timezone', 'UTC');

        if ($value instanceof CarbonInterface) {
            $carbon = $value->copy();
            if ($fromDatabase) {
                $carbon = Carbon::parse($carbon->toDateTimeString(), 'UTC');
            }

            return $carbon->setTimezone($appTimezone);
        }

        if (is_string($value)) {
            $carbon = $fromDatabase
                ? Carbon::parse($value, 'UTC')
                : Carbon::parse($value);

            return $carbon->setTimezone($appTimezone);
        }

        return null;
    }

    protected function buildMountCallback(): \Closure
    {
        return function (?Form $form, array $arguments) {
            if (!$form) {
                return;
            }

            $startFromArguments = $arguments['event']['start'] ?? null;
            $endFromArguments = $arguments['event']['end'] ?? null;

            $formStart = $startFromArguments
                ? $this->normalizeEventTime($startFromArguments)
                : $this->normalizeEventTime($this->record->getRawOriginal('start_time'), true);

            $formEnd = $endFromArguments
                ? $this->normalizeEventTime($endFromArguments)
                : $this->normalizeEventTime($this->record->getRawOriginal('end_time'), true);

            $form->fill([
                'doctor_id' => $this->record->doctor_id,
                'start_time' => $formStart,
                'end_time' => $formEnd,
            ]);
        };
    }

    protected function extractTimeComponent(mixed $value): ?string
    {
        if (!$value) {
            return null;
        }

        $timezone = Config::get('app.timezone', 'UTC');

        if ($value instanceof CarbonInterface) {
            return $value->copy()->setTimezone($timezone)->format('H:i:s');
        }

        if (is_string($value)) {
            try {
                return Carbon::parse($value, $timezone)->setTimezone($timezone)->format('H:i:s');
            } catch (\Throwable) {
                return null;
            }
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone($timezone)->format('H:i:s');
        }

        return null;
    }
}
