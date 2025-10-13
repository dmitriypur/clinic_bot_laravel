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
use Filament\Forms\Components\CheckboxList;
use App\Services\MassShiftCreator;
use App\Services\ShiftService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $quickActionsJs = '';

        if (!$isDoctor) {
            $quickActionsJs = <<<'JS'
            const container = el.querySelector('.fc-event-main-frame') || el;
            if (container) {
                if (getComputedStyle(container).position === 'static') {
                    container.style.position = 'relative';
                }

                if (!container.querySelector('.fc-shift-actions')) {
                    const actionBar = document.createElement('div');
                    actionBar.className = 'fc-shift-actions';
                    actionBar.style.position = 'absolute';
                    actionBar.style.top = '4px';
                    actionBar.style.right = '4px';
                    actionBar.style.display = 'none';
                    actionBar.style.gap = '4px';
                    actionBar.style.zIndex = '20';

                    const createButton = (label, action, svg, styles = {}) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.setAttribute('aria-label', label);
                        button.dataset.action = action;
                        button.style.width = '26px';
                        button.style.height = '26px';
                        button.style.borderRadius = '9999px';
                        button.style.border = '1px solid rgba(15, 23, 42, 0.08)';
                        button.style.background = 'rgba(255, 255, 255, 0.95)';
                        button.style.display = 'grid';
                        button.style.placeItems = 'center';
                        button.style.cursor = 'pointer';
                        button.style.transition = 'background-color 0.2s ease, transform 0.2s ease';
                        button.innerHTML = svg;

                        Object.entries(styles).forEach(([key, value]) => {
                            button.style[key] = value;
                        });

                        button.addEventListener('mouseenter', () => {
                            button.style.transform = 'scale(1.05)';
                        });

                        button.addEventListener('mouseleave', () => {
                            button.style.transform = 'scale(1)';
                        });

                        return button;
                    };

                    const editButton = createButton(
                        'Редактировать смену',
                        'edit',
                        `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
                        </svg>
                        `,
                        {
                            background: 'rgba(238, 255, 233, 0.95)',
                            padding:'5px',
                            color: '#17af26ff',
                        }
                    );

                    const deleteButton = createButton(
                        'Удалить смену',
                        'delete',
                        `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
                        </svg>
                        `,
                        {
                            background: 'rgba(255, 234, 234, 0.95)',
                            padding:'5px',
                            color: '#b91c1c',
                        }
                    );

                    deleteButton.addEventListener('mouseenter', () => {
                        deleteButton.style.background = '#fee2e2';
                    });

                    deleteButton.addEventListener('mouseleave', () => {
                        deleteButton.style.background = 'rgba(254, 226, 226, 0.95)';
                    });

                    actionBar.appendChild(editButton);
                    actionBar.appendChild(deleteButton);
                    container.appendChild(actionBar);

                    const showActions = () => {
                        actionBar.style.display = 'flex';
                    };

                    const hideActions = () => {
                        actionBar.style.display = 'none';
                    };

                    el.addEventListener('mouseenter', showActions);
                    el.addEventListener('mouseleave', hideActions);
                    editButton.addEventListener('focus', showActions);
                    deleteButton.addEventListener('focus', showActions);

                    const resolveComponent = () => {
                        const wireRoot = el.closest('[wire\\:id]');
                        if (!wireRoot || !window.Livewire || typeof window.Livewire.find !== 'function') {
                            return null;
                        }

                        return window.Livewire.find(wireRoot.getAttribute('wire:id'));
                    };

                    const payload = {
                        id: info.event.id,
                        start: info.event.startStr,
                        end: info.event.endStr,
                        extendedProps: info.event.extendedProps || {},
                    };

                    editButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        const component = resolveComponent();
                        if (component) {
                            component.call('openShiftEditModal', payload);
                        }
                        hideActions();
                    });

                    deleteButton.addEventListener('click', (event) => {
                        event.preventDefault();
                        event.stopPropagation();
                        const component = resolveComponent();
                        if (component) {
                            component.call('openShiftDeleteModal', payload);
                        }
                        hideActions();
                    });
                }
            }
            JS;
        }

        return [
            'firstDay' => 1, // Понедельник - первый день недели
            'headerToolbar' => [
                'left' => 'prev,next today',  // Навигация по датам
                'center' => 'title',          // Заголовок с текущей датой
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'  // Переключатели видов
            ],
            'initialView' => 'dayGridMonth',  // Начальный вид - неделя по времени
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
            'eventDidMount' => <<<JS
            function(info) {
                const el = info.el;

                if (info.event.extendedProps && info.event.extendedProps.is_past) {
                    el.style.opacity = '0.6';
                    el.style.filter = 'grayscale(50%)';
                    el.title = 'Прошедшая смена: ' + info.event.title;
                } else {
                    el.title = 'Активная смена: ' + info.event.title;
                }

                {$quickActionsJs}
            }
            JS,
        ];
    }

    public function eventContent(): string
    {
        $user = auth()->user();

        if ($user && !$user->isDoctor()) {
            return <<<'JS'
function(arg) {
    const wrapper = document.createElement('div');
    wrapper.className = 'fc-shift-wrapper';
    wrapper.style.display = 'flex';
    wrapper.style.alignItems = 'center';
    wrapper.style.justifyContent = 'space-between';
    wrapper.style.gap = '8px';
    wrapper.style.width = '100%';

    const colorIndicator = document.createElement('span');
    colorIndicator.className = 'fc-shift-color';
    colorIndicator.style.width = '6px';
    colorIndicator.style.height = '6px';
    colorIndicator.style.borderRadius = '9999px';
    colorIndicator.style.background = arg.backgroundColor || arg.event.backgroundColor || '#3B82F6';
    colorIndicator.style.flex = '0 0 auto';

    const title = document.createElement('div');
    title.className = 'fc-shift-title';
    title.textContent = arg.event.title || '';
    title.style.flex = '1 1 auto';
    title.style.minWidth = '0';
    title.style.overflow = 'hidden';
    title.style.textOverflow = 'ellipsis';
    title.style.whiteSpace = 'nowrap';

    const actions = document.createElement('div');
    actions.className = 'fc-shift-actions';
    actions.dataset.eventId = arg.event.id;
    actions.style.display = 'flex';
    actions.style.gap = '4px';
    actions.style.opacity = '0';
    actions.style.pointerEvents = 'none';
    actions.style.transition = 'opacity 0.18s ease-in-out';

    const createButton = (label, action, svg, styles = {}) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'fc-shift-action';
        button.dataset.action = action;
        button.setAttribute('aria-label', label);
        button.innerHTML = svg;
        button.style.width = '26px';
        button.style.height = '26px';
        button.style.borderRadius = '9999px';
        button.style.border = '1px solid rgba(15, 23, 42, 0.08)';
        button.style.background = 'rgba(255, 255, 255, 0.95)';
        button.style.display = 'grid';
        button.style.placeItems = 'center';
        button.style.cursor = 'pointer';
        button.style.transition = 'background-color 0.2s ease, transform 0.2s ease';
        Object.entries(styles).forEach(([key, value]) => {
            button.style[key] = value;
        });
        button.addEventListener('mouseenter', () => {
            button.style.transform = 'scale(1.05)';
        });
        button.addEventListener('mouseleave', () => {
            button.style.transform = 'scale(1)';
        });
        return button;
    };

    const editButton = createButton(
        'Редактировать смену',
        'edit',
        `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
        <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L6.832 19.82a4.5 4.5 0 0 1-1.897 1.13l-2.685.8.8-2.685a4.5 4.5 0 0 1 1.13-1.897L16.863 4.487Zm0 0L19.5 7.125" />
        </svg>
        `,
        {
            background: 'rgba(238, 255, 233, 0.95)',
            padding:'5px',
            color: '#17af26ff',
        }
    );

    const deleteButton = createButton(
        'Удалить смену',
        'delete',
        `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-6">
        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
        </svg>
        `,
        {
            background: 'rgba(255, 234, 234, 0.95)',
            padding:'5px',
            color: '#b91c1c',
        }
    );

    deleteButton.addEventListener('mouseenter', () => {
        deleteButton.style.background = '#fee2e2';
    });
    deleteButton.addEventListener('mouseleave', () => {
        deleteButton.style.background = 'rgba(254, 226, 226, 0.95)';
    });

    actions.appendChild(editButton);
    actions.appendChild(deleteButton);

    wrapper.appendChild(colorIndicator);
    wrapper.appendChild(title);
    wrapper.appendChild(actions);

    return { domNodes: [wrapper] };
}
JS;
        }

        return 'null';
    }

    public function eventDidMount(): string
    {
        $user = auth()->user();

        if ($user && !$user->isDoctor()) {
            return <<<'JS'
function(info) {
    const el = info.el;

    if (info.event.extendedProps && info.event.extendedProps.is_past) {
        el.style.opacity = '0.6';
        el.style.filter = 'grayscale(50%)';
        el.title = 'Прошедшая смена: ' + info.event.title;
    } else {
        el.title = 'Активная смена: ' + info.event.title;
    }

    const container = el.querySelector('.fc-event-main-frame') || el;
    if (container) {
        const styles = window.getComputedStyle(container);
        if (styles.position === 'static') {
            container.style.position = 'relative';
        }
        if (styles.overflow === 'hidden') {
            container.style.overflow = 'visible';
        }
    }

    const actions = el.querySelector('.fc-shift-actions');
    if (!actions) {
        return;
    }

    const showActions = () => {
        actions.style.opacity = '1';
        actions.style.pointerEvents = 'auto';
    };

    const hideActions = () => {
        actions.style.opacity = '0';
        actions.style.pointerEvents = 'none';
    };

    el.addEventListener('mouseenter', showActions);
    el.addEventListener('mouseleave', hideActions);

    actions.querySelectorAll('.fc-shift-action').forEach((button) => {
        button.addEventListener('focus', showActions);
        button.addEventListener('blur', hideActions);
        button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();

            const wireRoot = el.closest('[wire\\:id]');
            if (!wireRoot || !window.Livewire || typeof window.Livewire.find !== 'function') {
                return;
            }

            const component = window.Livewire.find(wireRoot.getAttribute('wire:id'));
            if (!component) {
                return;
            }

            const payload = {
                id: info.event.id,
                start: info.event.startStr,
                end: info.event.endStr,
                extendedProps: info.event.extendedProps || {},
            };

            if (button.dataset.action === 'edit') {
                component.call('openShiftEditModal', payload);
            } else if (button.dataset.action === 'delete') {
                component.call('openShiftDeleteModal', payload);
            }

            hideActions();
        });
    });
}
JS;
        }

        return <<<'JS'
function(info) {
    const el = info.el;

    if (info.event.extendedProps && info.event.extendedProps.is_past) {
        el.style.opacity = '0.6';
        el.style.filter = 'grayscale(50%)';
        el.title = 'Прошедшая смена: ' + info.event.title;
    } else {
        el.title = 'Активная смена: ' + info.event.title;
    }
}
JS;
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

    public function openShiftEditModal(array $event): void
    {
        $user = auth()->user();

        if ($user->isDoctor()) {
            Notification::make()
                ->title('Недостаточно прав')
                ->body('Редактирование смен недоступно для врача.')
                ->warning()
                ->send();
            return;
        }

        $shift = $this->findShiftRecord((int) ($event['id'] ?? 0));

        if (!$shift) {
            Notification::make()
                ->title('Смена не найдена')
                ->body('Не удалось открыть смену для редактирования.')
                ->danger()
                ->send();
            return;
        }

        $this->record = $shift;

        $this->mountAction('edit', [
            'type' => 'quick-action',
            'event' => $this->prepareEventPayload($event),
        ]);
    }

    public function openShiftDeleteModal(array $event): void
    {
        $user = auth()->user();

        if ($user->isDoctor()) {
            Notification::make()
                ->title('Недостаточно прав')
                ->body('Удаление смен недоступно для врача.')
                ->warning()
                ->send();
            return;
        }

        $shift = $this->findShiftRecord((int) ($event['id'] ?? 0));

        if (!$shift) {
            Notification::make()
                ->title('Смена не найдена')
                ->body('Не удалось открыть смену для удаления.')
                ->danger()
                ->send();
            return;
        }

        $this->record = $shift;

        $this->mountAction('delete', [
            'type' => 'quick-action',
            'event' => $this->prepareEventPayload($event),
        ]);
    }

    protected function findShiftRecord(int $shiftId): ?DoctorShift
    {
        if (!$shiftId) {
            return null;
        }

        try {
            /** @var DoctorShift $shift */
            $shift = $this->resolveRecord($shiftId);
        } catch (ModelNotFoundException) {
            return null;
        }

        if (!$shift) {
            return null;
        }

        return $shift;
    }

    protected function prepareEventPayload(array $event): array
    {
        $payload = [
            'id' => $event['id'] ?? null,
            'start' => $event['start'] ?? null,
            'end' => $event['end'] ?? null,
            'extendedProps' => (array) ($event['extendedProps'] ?? []),
        ];

        if (!$payload['start'] && $this->record instanceof DoctorShift) {
            $start = $this->normalizeEventTime($this->record->getRawOriginal('start_time'), true);
            $payload['start'] = $start ? $start->toIso8601String() : null;
        }

        if (!$payload['end'] && $this->record instanceof DoctorShift) {
            $end = $this->normalizeEventTime($this->record->getRawOriginal('end_time'), true);
            $payload['end'] = $end ? $end->toIso8601String() : null;
        }

        return $payload;
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

            CheckboxList::make('excluded_weekdays')
                ->label('Исключить дни недели')
                ->options([
                    1 => 'Понедельник',
                    2 => 'Вторник',
                    3 => 'Среда',
                    4 => 'Четверг',
                    5 => 'Пятница',
                    6 => 'Суббота',
                    7 => 'Воскресенье',
                ])
                ->columns(2)
                ->helperText('Выбранные дни недели будут пропущены при массовом создании смен.')
                ->default([]),

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
                                'excluded_weekdays' => $data['excluded_weekdays'] ?? [],
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
                            'excluded_weekdays' => $data['excluded_weekdays'] ?? [],
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
