<?php

namespace App\Filament\Widgets;

use App\Models\DoctorShift;
use App\Models\Cabinet;
use App\Services\CalendarFilterService;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Actions\Action;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;

/**
 * Виджет календаря расписания для всех кабинетов
 * 
 * Отображает календарь с расписанием врачей по всем кабинетам.
 * Позволяет создавать, редактировать и удалять смены врачей.
 * Включает фильтрацию по ролям пользователей и выбор кабинета при создании смены.
 */
class AllCabinetsScheduleWidget extends BaseDoctorShiftScheduleWidget
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

        return $this->makeBaseCalendarConfig($isDoctor);
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

    protected function findShiftRecord(int $shiftId): ?DoctorShift
    {
        return parent::findShiftRecord($shiftId);
    }

    protected function prepareEventPayload(array $event): array
    {
        return parent::prepareEventPayload($event);
    }

    public function getFormSchema(): array
    {
        $fields = [
            Select::make('cabinet_id')
                ->label('Кабинет')
                ->required()
                ->searchable()
                ->options(function () {
                    $user = auth()->user();

                    $query = Cabinet::with('branch');

                    if ($user->isPartner()) {
                        $query->whereHas('branch', function ($q) use ($user) {
                            $q->where('clinic_id', $user->clinic_id);
                        });
                    } elseif ($user->isDoctor()) {
                        $query->whereHas('branch.doctors', function ($q) use ($user) {
                            $q->where('doctor_id', $user->doctor_id);
                        });
                    }

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
        ];

        return array_merge($fields, $this->getSharedShiftFields());
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
                    $cabinetId = $data['cabinet_id'] ?? $this->record->cabinet_id;
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

                    $this->createShiftSeries($data, (int) $data['cabinet_id']);
                });
        }
        
        return $actions;
    }

    protected function getAdditionalMountFormFillData(): array
    {
        if (!$this->record instanceof DoctorShift) {
            return [];
        }

        return [
            'cabinet_id' => $this->record->cabinet_id,
            'doctor_id' => $this->record->doctor_id,
        ];
    }

}
