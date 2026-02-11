<?php

namespace App\Filament\Widgets;

use App\Models\DoctorShift;
use App\Services\MassShiftCreator;
use App\Services\ShiftService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

abstract class BaseDoctorShiftScheduleWidget extends FullCalendarWidget
{
    public \Illuminate\Database\Eloquent\Model|string|null $model = DoctorShift::class;

    public function eventContent(): string
    {
        $user = auth()->user();

        if ($user && ! $user->isDoctor()) {
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

        if ($user && ! $user->isDoctor()) {
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

    public function openShiftEditModal(array $event): void
    {
        if (! $this->ensureCanManageShifts('Редактирование смен недоступно для врача.')) {
            return;
        }

        $shift = $this->findShiftRecord((int) ($event['id'] ?? 0));

        if (! $shift) {
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
        if (! $this->ensureCanManageShifts('Удаление смен недоступно для врача.')) {
            return;
        }

        $shift = $this->findShiftRecord((int) ($event['id'] ?? 0));

        if (! $shift) {
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

    protected function ensureCanManageShifts(string $message): bool
    {
        $user = auth()->user();

        if ($user && ! $user->isDoctor()) {
            return true;
        }

        Notification::make()
            ->title('Недостаточно прав')
            ->body($message)
            ->warning()
            ->send();

        return false;
    }

    protected function findShiftRecord(int $shiftId): ?DoctorShift
    {
        if (! $shiftId) {
            return null;
        }

        try {
            /** @var DoctorShift $shift */
            $shift = $this->resolveRecord($shiftId);
        } catch (ModelNotFoundException) {
            return null;
        }

        return $shift ?: null;
    }

    protected function prepareEventPayload(array $event): array
    {
        $payload = [
            'id' => $event['id'] ?? null,
            'start' => $event['start'] ?? null,
            'end' => $event['end'] ?? null,
            'extendedProps' => (array) ($event['extendedProps'] ?? []),
        ];

        if (! $payload['start'] && $this->record instanceof DoctorShift) {
            $start = $this->normalizeEventTime($this->record->getRawOriginal('start_time'), true);
            $payload['start'] = $start ? $start->toIso8601String() : null;
        }

        if (! $payload['end'] && $this->record instanceof DoctorShift) {
            $end = $this->normalizeEventTime($this->record->getRawOriginal('end_time'), true);
            $payload['end'] = $end ? $end->toIso8601String() : null;
        }

        return $payload;
    }

    protected function buildMountCallback(): \Closure
    {
        return function (?Form $form, array $arguments) {
            if (! $form) {
                return;
            }

            $form->fill($this->getMountFormFillData($arguments));
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function getMountFormFillData(array $arguments): array
    {
        $startFromArguments = $arguments['event']['start'] ?? null;
        $endFromArguments = $arguments['event']['end'] ?? null;

        $formStart = $startFromArguments
            ? $this->normalizeEventTime($startFromArguments)
            : $this->normalizeEventTime($this->record?->getRawOriginal('start_time'), true);

        $formEnd = $endFromArguments
            ? $this->normalizeEventTime($endFromArguments)
            : $this->normalizeEventTime($this->record?->getRawOriginal('end_time'), true);

        return array_merge([
            'start_time' => $formStart,
            'end_time' => $formEnd,
        ], $this->getAdditionalMountFormFillData());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAdditionalMountFormFillData(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (array_key_exists('start_time', $data)) {
            $data['start_time'] = $this->normalizeEventTime($data['start_time'], true);
        }

        if (array_key_exists('end_time', $data)) {
            $data['end_time'] = $this->normalizeEventTime($data['end_time'], true);
        }

        return $this->mutateAdditionalFormDataBeforeFill($data);
    }

    protected function mutateAdditionalFormDataBeforeFill(array $data): array
    {
        return $data;
    }

    /**
     * @return array<int, mixed>
     */
    protected function getSharedShiftFields(): array
    {
        return [
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

    protected function createShiftSeries(array $data, int $cabinetId): bool
    {
        /** @var MassShiftCreator $creator */
        $creator = app(MassShiftCreator::class);

        $workdayStart = $this->extractTimeComponent($data['start_time'] ?? null);
        $workdayEnd = $this->extractTimeComponent($data['end_time'] ?? null);

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
            $this->notifyValidationErrors($exception, 'Не удалось создать смены');

            return false;
        } catch (\Throwable $throwable) {
            $this->notifyGenericError($throwable, 'Не удалось создать смены');

            return false;
        }

        $this->notifySeriesCreated($created);
        $this->refreshRecords();

        return true;
    }

    protected function updateShiftRecord(array $data, int $cabinetId): void
    {
        /** @var ShiftService $shiftService */
        $shiftService = app(ShiftService::class);

        $hasBreak = (bool) ($data['has_break'] ?? false);
        $start = $data['start_time'] ?? null;
        $end = $data['end_time'] ?? null;
        $workdayStart = $this->extractTimeComponent($data['start_time'] ?? null);
        $workdayEnd = $this->extractTimeComponent($data['end_time'] ?? null);

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
    }

    protected function normalizeEventTime(mixed $value, bool $fromDatabase = false): ?Carbon
    {
        if (! $value) {
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

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone($appTimezone);
        }

        return null;
    }

    protected function extractTimeComponent(mixed $value): ?string
    {
        if (! $value) {
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

    protected function makeBaseCalendarConfig(bool $isDoctor, array $overrides = []): array
    {
        $defaults = [
            'firstDay' => 1,
            'headerToolbar' => [
                'left' => 'prev,next today',
                'center' => 'title',
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
            ],
            'initialView' => 'dayGridMonth',
            'navLinks' => true,
            'editable' => ! $isDoctor,
            'selectable' => ! $isDoctor,
            'selectMirror' => ! $isDoctor,
            'dayMaxEvents' => true,
            'weekends' => true,
            'locale' => 'ru',
            'buttonText' => [
                'today' => 'Сегодня',
                'month' => 'Месяц',
                'week' => 'Неделя',
                'day' => 'День',
                'list' => 'Список',
            ],
            'allDaySlot' => false,
            'slotMinTime' => '08:00:00',
            'slotMaxTime' => '20:00:00',
            'slotDuration' => '00:15:00',
            'snapDuration' => '00:15:00',
            'slotLabelFormat' => [
                'hour' => '2-digit',
                'minute' => '2-digit',
                'hour12' => false,
            ],
        ];

        if (empty($overrides)) {
            return $defaults;
        }

        return array_replace_recursive($defaults, $overrides);
    }

    /**
     * @param  iterable<int, mixed>|Collection|array  $created
     */
    protected function notifySeriesCreated(iterable $created): void
    {
        $count = $created instanceof Collection ? $created->count() : (is_array($created) ? count($created) : 1);

        $title = $count > 1
            ? "Создано смен: {$count}"
            : 'Смена создана';

        Notification::make()
            ->title($title)
            ->body('Смена врача успешно добавлена в расписание')
            ->success()
            ->send();
    }

    protected function notifyGenericError(\Throwable $throwable, string $title = 'Не удалось выполнить операцию'): void
    {
        Notification::make()
            ->title($title)
            ->body('Произошла непредвиденная ошибка. '.$throwable->getMessage())
            ->danger()
            ->send();
    }

    protected function notifyValidationErrors(\Throwable $exception, string $title = 'Не удалось выполнить операцию'): void
    {
        if (! method_exists($exception, 'errors')) {
            $this->notifyGenericError($exception, $title);

            return;
        }

        $messages = collect($exception->errors())
            ->flatten()
            ->implode("\n");

        Notification::make()
            ->title($title)
            ->body($messages ?: 'Проверьте введенные данные и попробуйте снова.')
            ->danger()
            ->send();
    }
}
