<?php

namespace App\Filament\Widgets;

use App\Models\DoctorShift;
use App\Models\Cabinet;
use App\Services\CalendarFilterService;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
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
     * Сервис для работы с фильтрами
     */
    protected ?CalendarFilterService $filterService = null;

    public function getFilterService(): CalendarFilterService
    {
        if ($this->filterService === null) {
            $this->filterService = app(CalendarFilterService::class);
        }
        return $this->filterService;
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
            'slotMinTime' => '08:00:00',      // Минимальное время отображения (расширили для ранних смен)
            'slotMaxTime' => '20:00:00',      // Максимальное время отображения (расширили для поздних смен)
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

    protected function viewAction(): Action
    {
        return parent::viewAction()
            ->mountUsing($this->buildMountCallback());
    }

    /**
     * Получение событий для отображения в календаре
     * Загружает смены врачей по всем кабинетам с фильтрацией по ролям и пользовательским фильтрам
     */
    public function fetchEvents(array $fetchInfo): array
    {
        $user = auth()->user();
        $rangeStart = Carbon::parse($fetchInfo['start'])->setTimezone('UTC');
        $rangeEnd = Carbon::parse($fetchInfo['end'])->setTimezone('UTC');

        // Базовый запрос смен в указанном диапазоне дат
        $query = DoctorShift::query()
            ->whereBetween('start_time', [$rangeStart, $rangeEnd])
            ->with(['doctor', 'cabinet.branch']);

        // Дополнительно загружаем смены для текущего дня, если они не попадают в диапазон
        $todayStartLocal = now()->startOfDay();
        $todayEndLocal = now()->endOfDay();
        $todayStart = $todayStartLocal->copy()->setTimezone('UTC');
        $todayEnd = $todayEndLocal->copy()->setTimezone('UTC');

        $todayQuery = DoctorShift::query()
            ->whereBetween('start_time', [$todayStart, $todayEnd])
            ->with(['doctor', 'cabinet.branch']);

        // Применяем пользовательские фильтры к основному запросу
        $this->getFilterService()->applyShiftFilters($query, $this->filters, $user);

        // Применяем пользовательские фильтры к запросу для текущего дня
        $this->getFilterService()->applyShiftFilters($todayQuery, $this->filters, $user);

        // Объединяем результаты
        $allShifts = $query->get()->merge($todayQuery->get())->unique('id');

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
                    'title' => ($shift->doctor->full_name ?? 'Врач не назначен') . ' - ' . ($shift->cabinet->name ?? 'Кабинет не указан'),
                    'start' => $shiftStart->toIso8601String(),
                    'end' => $shiftEnd->toIso8601String(),
                    'backgroundColor' => $this->getShiftColor($shift),
                    'borderColor' => $this->getShiftColor($shift),
                    'classNames' => $isPast ? ['past-shift'] : ['active-shift'],
                    'extendedProps' => [
                        'doctor_id' => $shift->doctor_id,
                        'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен',
                        'cabinet_id' => $shift->cabinet_id,
                        'cabinet_name' => $shift->cabinet->name ?? 'Кабинет не указан',
                        'branch_name' => $shift->cabinet->branch->name ?? 'Филиал не указан',
                        'city_id' => $shift->cabinet->branch->city_id ?? null,
                        'is_past' => $isPast,
                        'shift_start_time' => $shiftStart->format('Y-m-d H:i:s'),
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

    protected function getShiftColor(DoctorShift $shift): string
    {
        $appTimezone = Config::get('app.timezone', 'UTC');
        $shiftStart = Carbon::parse($shift->getRawOriginal('start_time'), 'UTC')->setTimezone($appTimezone);

        if ($shiftStart->isPast()) {
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
            '#31c090', // зеленый
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
                ->mountUsing($this->buildMountCallback())
                ->action(function (array $data) {
                    /** @var ShiftService $shiftService */
                    $shiftService = app(ShiftService::class);
                    $hasBreak = (bool) ($data['has_break'] ?? false);
                    $start = $data['start_time'];
                    $end = $data['end_time'];
                    $cabinetId = $data['cabinet_id'] ?? $this->record->cabinet_id;
                    $workdayStart = $this->extractTimeComponent($data['start_time']);
                    $workdayEnd = $this->extractTimeComponent($data['end_time']);

                    if ($start instanceof CarbonInterface) {
                        $start = $start->toDateTimeString();
                    }

                    if ($end instanceof CarbonInterface) {
                        $end = $end->toDateTimeString();
                    }

                    if ($hasBreak) {
                        /** @var MassShiftCreator $creator */
                        $creator = app(MassShiftCreator::class);

                        DB::transaction(function () use ($creator, $data, $start, $end, $cabinetId, $workdayStart, $workdayEnd) {
                            $this->record->delete();

                            $creator->createSeries([
                                'doctor_id' => $data['doctor_id'],
                                'cabinet_id' => $cabinetId,
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

                    $shiftService->update($this->record, [
                        'doctor_id' => $data['doctor_id'],
                        'cabinet_id' => $cabinetId,
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

            Action::make('duplicate')
                ->label('Дублировать')
                ->icon('heroicon-o-document-duplicate')
                ->color('info')
                ->mountUsing($this->buildMountCallback())
                ->action(function () {
                    $originalShift = $this->record;

                    $newStartTime = Carbon::parse($originalShift->start_time)->addDay();
                    $newEndTime = Carbon::parse($originalShift->end_time)->addDay();

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
                    if (empty($data['cabinet_id'])) {
                        Notification::make()
                            ->title('Ошибка')
                            ->body('Не выбран кабинет')
                            ->danger()
                            ->send();
                        return;
                    }

                    /** @var MassShiftCreator $creator */
                    $creator = app(MassShiftCreator::class);
                    $workdayStart = $this->extractTimeComponent($data['start_time']);
                    $workdayEnd = $this->extractTimeComponent($data['end_time']);

                    try {
                        $created = $creator->createSeries([
                            'doctor_id' => $data['doctor_id'],
                            'cabinet_id' => $data['cabinet_id'],
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
                        ->body('Смена врача успешно добавлена в расписание')
                        ->success()
                        ->send();

                    $this->refreshRecords();
                });
        }

        return $actions;
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
                'cabinet_id' => $this->record->cabinet_id,
                'doctor_id' => $this->record->doctor_id,
                'start_time' => $formStart,
                'end_time' => $formEnd,
            ]);
        };
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
