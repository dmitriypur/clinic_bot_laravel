<?php

namespace App\Filament\Resources\ApplicationResource\Widgets;

use App\Services\CalendarFilterService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Grid;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class CalendarFiltersWidget extends Widget implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.resources.application-resource.widgets.calendar-filters-widget';
    
    public array $filters = [
        'clinic_ids' => [],
        'branch_ids' => [],
        'doctor_ids' => [],
        'date_from' => null,
        'date_to' => null,
    ];

    public bool $showFilters = false;
    
    protected CalendarFilterService $filterService;
    
    public function mount()
    {
        $this->filterService = app(CalendarFilterService::class);
        $this->form->fill($this->filters);
    }

    public function form(Form $form): Form
    {
        $user = auth()->user();
        
        return $form
            ->schema([
                Grid::make(3)
                    ->schema([
                        DatePicker::make('date_from')
                            ->label('Дата с')
                            ->displayFormat('d.m.Y')
                            ->native(false)
                            ->reactive()
                            ->afterStateUpdated(fn () => $this->updateFilters()),
                            
                        DatePicker::make('date_to')
                            ->label('Дата по')
                            ->displayFormat('d.m.Y')
                            ->native(false)
                            ->reactive()
                            ->afterStateUpdated(fn () => $this->updateFilters()),
                            
                        Select::make('clinic_ids')
                            ->label('Клиники')
                            ->multiple()
                            ->searchable()
                            ->options(fn() => $this->filterService->getAvailableClinics($user))
                            ->reactive()
                            ->afterStateUpdated(fn (Set $set) => $set('branch_ids', []))
                            ->afterStateUpdated(fn () => $this->updateFilters()),
                    ]),
                    
                Grid::make(2)
                    ->schema([
                        Select::make('branch_ids')
                            ->label('Филиалы')
                            ->multiple()
                            ->searchable()
                            ->options(function (Get $get) use ($user) {
                                $clinicIds = $get('clinic_ids');
                                return $this->filterService->getAvailableBranches($user, $clinicIds);
                            })
                            ->reactive()
                            ->afterStateUpdated(fn (Set $set) => $set('doctor_ids', []))
                            ->afterStateUpdated(fn () => $this->updateFilters()),
                            
                        Select::make('doctor_ids')
                            ->label('Врачи')
                            ->multiple()
                            ->searchable()
                            ->options(function (Get $get) use ($user) {
                                $branchIds = $get('branch_ids');
                                return $this->filterService->getAvailableDoctors($user, $branchIds);
                            })
                            ->reactive()
                            ->afterStateUpdated(fn () => $this->updateFilters()),
                    ]),
            ])
            ->statePath('filters');
    }

    #[On('toggleCalendarFilters')]
    public function toggleFilters(): void
    {
        $this->showFilters = !$this->showFilters;
    }

    public function clearFilters(): void
    {
        $this->filters = [
            'clinic_ids' => [],
            'branch_ids' => [],
            'doctor_ids' => [],
            'date_from' => null,
            'date_to' => null,
        ];
        
        $this->form->fill($this->filters);
        $this->updateFilters();
        
        Notification::make()
            ->title('Фильтры очищены')
            ->success()
            ->send();
    }

    public function updateFilters(): void
    {
        $this->filters = $this->form->getState();
        
        // Валидация фильтров
        $errors = $this->filterService->validateFilters($this->filters);
        
        if (!empty($errors)) {
            Notification::make()
                ->title('Ошибка валидации')
                ->body(implode(', ', $errors))
                ->danger()
                ->send();
            return;
        }
        
        // Отправляем событие обновления фильтров
        $this->dispatch('filtersUpdated', filters: $this->filters);
    }
}
