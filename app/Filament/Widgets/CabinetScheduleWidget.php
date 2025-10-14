<?php

namespace App\Filament\Widgets;

use App\Models\Cabinet;
use App\Models\DoctorShift;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Config;

class CabinetScheduleWidget extends BaseDoctorShiftScheduleWidget
{
    public ?int $cabinetId = null;

    protected ?Cabinet $cachedCabinet = null;

    public function getCabinetId(): ?int
    {
        return $this->cabinetId;
    }

    public function setCabinetId(?int $cabinetId): void
    {
        $this->cabinetId = $cabinetId;
    }

    public function boot(): void
    {
        if (!$this->cabinetId) {
            $this->cabinetId = (int) (request()->route('record') ?? 0) ?: null;
        }
    }

    public function mount(): void
    {
        if (!$this->cabinetId) {
            $this->cabinetId = (int) (request()->route('record') ?? 0) ?: null;
        }
    }

    public function getCabinetIdFromContext(): ?int
    {
        if ($this->cabinetId) {
            return $this->cabinetId;
        }

        $record = request()->route('record');
        if ($record) {
            return $this->cabinetId = (int) $record;
        }

        try {
            $livewire = app('livewire')->current();
            if ($livewire && method_exists($livewire, 'getRecord')) {
                $record = $livewire->getRecord();
                if ($record) {
                    return $this->cabinetId = $record->id;
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }

    protected function getCabinetModel(array $relations = []): ?Cabinet
    {
        $cabinetId = $this->getCabinetIdFromContext();

        if (!$cabinetId) {
            return null;
        }

        if (!$this->cachedCabinet || $this->cachedCabinet->id !== $cabinetId) {
            $query = Cabinet::query();
            if (!empty($relations)) {
                $query->with($relations);
            }

            $this->cachedCabinet = $query->find($cabinetId);

            return $this->cachedCabinet;
        }

        if (!empty($relations)) {
            $this->cachedCabinet->loadMissing($relations);
        }

        return $this->cachedCabinet;
    }

    protected function getCabinetSlotDuration(): string
    {
        $cabinet = $this->getCabinetModel(['branch']);

        if (!$cabinet || !$cabinet->branch) {
            return '00:30:00';
        }

        $duration = $cabinet->branch->getEffectiveSlotDuration();

        $hours = intdiv($duration, 60);
        $minutes = $duration % 60;

        return sprintf('%02d:%02d:00', $hours, $minutes);
    }

    public static function canView(): bool
    {
        $route = request()->route();
        if (!$route) {
            return false;
        }

        $routeName = $route->getName();

        return str_contains($routeName, 'cabinets') && $route->parameter('record');
    }

    public function config(): array
    {
        $user = auth()->user();
        $isDoctor = $user && $user->isDoctor();
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
                        <path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165Л18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" />
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

        $config = $this->makeBaseCalendarConfig($isDoctor, [
            'slotDuration' => $slotDuration,
            'snapDuration' => $slotDuration,
        ]);

        $config['eventDidMount'] = <<<JS
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
        JS;

        return $config;
    }

    public function fetchEvents(array $fetchInfo): array
    {
        $cabinetId = $this->getCabinetIdFromContext();

        if (!$cabinetId) {
            return [];
        }

        $user = auth()->user();
        $rangeStart = Carbon::parse($fetchInfo['start'])->setTimezone('UTC');
        $rangeEnd = Carbon::parse($fetchInfo['end'])->setTimezone('UTC');

        $todayStart = now()->startOfDay()->setTimezone('UTC');
        $todayEnd = now()->endOfDay()->setTimezone('UTC');

        $query = DoctorShift::query()
            ->where('cabinet_id', $cabinetId)
            ->where(function ($q) use ($rangeStart, $rangeEnd, $todayStart, $todayEnd) {
                $q->whereBetween('start_time', [$rangeStart, $rangeEnd])
                    ->orWhereBetween('start_time', [$todayStart, $todayEnd]);
            })
            ->with(['doctor', 'cabinet.branch']);

        if ($user->isDoctor()) {
            $query->where('doctor_id', $user->doctor_id);
        } elseif ($user->isPartner()) {
            $cabinet = $this->getCabinetModel(['branch']);
            if (!$cabinet || $cabinet->branch->clinic_id !== $user->clinic_id) {
                return [];
            }
        }

        $shifts = $query->get()->unique('id');

        return $shifts
            ->map(function (DoctorShift $shift) {
                $appTimezone = Config::get('app.timezone', 'UTC');
                $shiftStart = Carbon::parse($shift->getRawOriginal('start_time'), 'UTC')->setTimezone($appTimezone);
                $shiftEnd = Carbon::parse($shift->getRawOriginal('end_time'), 'UTC')->setTimezone($appTimezone);

                $isPast = $shiftStart->isPast();
                $branch = $shift->cabinet->branch ?? null;

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
                        'city_id' => $branch->city_id ?? null,
                        'is_past' => $isPast,
                        'shift_start_time' => $shiftStart->format('Y-m-d H:i:s'),
                    ],
                ];
            })
            ->toArray();
    }

    public function getFormSchema(): array
    {
        $fields = [
            Select::make('doctor_id')
                ->label('Врач')
                ->required()
                ->searchable()
                ->options(function () {
                    $cabinet = $this->getCabinetModel(['branch.doctors']);

                    if (!$cabinet || !$cabinet->branch) {
                        return [];
                    }

                    return $cabinet->branch->doctors
                        ->mapWithKeys(fn ($doctor) => [$doctor->id => $doctor->full_name])
                        ->toArray();
                }),
        ];

        return array_merge($fields, $this->getSharedShiftFields());
    }

    protected function getShiftColor(DoctorShift $shift): string
    {
        $appTimezone = Config::get('app.timezone', 'UTC');
        $shiftStart = Carbon::parse($shift->getRawOriginal('start_time'), 'UTC')->setTimezone($appTimezone);
        $now = now($appTimezone);

        if ($shiftStart->isBefore($now->startOfDay())) {
            $pastColors = [
                '#9CA3AF',
                '#6B7280',
                '#4B5563',
                '#374151',
            ];

            $doctorId = $shift->doctor_id ?? 0;

            return $pastColors[$doctorId % count($pastColors)];
        }

        $colors = [
            '#3B82F6',
            '#31c090',
            '#F59E0B',
            '#8B5CF6',
            '#06B6D4',
            '#84CC16',
            '#F97316',
        ];

        $doctorId = $shift->doctor_id ?? 0;

        return $colors[$doctorId % count($colors)];
    }

    protected function modalActions(): array
    {
        $user = auth()->user();

        if ($user->isDoctor()) {
            return [];
        }

        return [
            \Saade\FilamentFullCalendar\Actions\EditAction::make()
                ->mountUsing($this->buildMountCallback())
                ->action(function (array $data) {
                    $cabinetId = $this->record->cabinet_id ?? $this->getCabinetIdFromContext();

                    if (!$cabinetId) {
                        Notification::make()
                            ->title('Ошибка')
                            ->body('Не удалось определить кабинет смены')
                            ->danger()
                            ->send();

                        return;
                    }

                    $this->updateShiftRecord($data, (int) $cabinetId);
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

        if ($user->isDoctor()) {
            return [];
        }

        return [
            \Saade\FilamentFullCalendar\Actions\CreateAction::make()
                ->mountUsing(function (Form $form, array $arguments) {
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

                    $this->createShiftSeries($data, (int) $cabinetId);
                }),
        ];
    }

    protected function getAdditionalMountFormFillData(): array
    {
        if (!$this->record instanceof DoctorShift) {
            return [];
        }

        return [
            'doctor_id' => $this->record->doctor_id,
        ];
    }
}
