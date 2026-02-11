<?php

namespace App\Filament\Filters;

use App\Services\CalendarFilterService;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class ApplicationFilters extends Filter
{
    public static function make(?string $name = null): static
    {
        return parent::make($name ?? 'application_filters')
            ->label('🔍 Фильтры заявок')
            ->form([
                DatePicker::make('date_from')
                    ->label('Дата с')
                    ->displayFormat('d.m.Y')
                    ->native(false),

                DatePicker::make('date_to')
                    ->label('Дата по')
                    ->displayFormat('d.m.Y')
                    ->native(false),

                Select::make('clinic_ids')
                    ->label('Клиники')
                    ->multiple()
                    ->searchable()
                    ->reactive()
                    ->options(function () {
                        $filterService = app(CalendarFilterService::class);

                        return $filterService->getAvailableClinics(auth()->user());
                    })
                    ->afterStateUpdated(function ($state, callable $set) {
                        $set('branch_ids', []);
                        $set('doctor_ids', []);
                    }),
                Select::make('branch_ids')
                    ->label('Филиалы')
                    ->multiple()
                    ->searchable()
                    ->reactive()
                    ->options(function (callable $get) {
                        $clinicIds = $get('clinic_ids') ?? [];
                        if (empty($clinicIds)) {
                            return [];
                        }
                        $filterService = app(CalendarFilterService::class);

                        return $filterService->getAvailableBranches(auth()->user(), $clinicIds);
                    })
                    ->afterStateUpdated(function ($state, callable $set) {
                        $set('doctor_ids', []);
                    }),

                Select::make('doctor_ids')
                    ->label('Врачи')
                    ->multiple()
                    ->searchable()
                    ->reactive()
                    ->options(function (callable $get) {
                        $branchIds = $get('branch_ids') ?? [];
                        if (empty($branchIds)) {
                            return [];
                        }
                        $filterService = app(CalendarFilterService::class);

                        return $filterService->getAvailableDoctors(auth()->user(), $branchIds);
                    }),

                Select::make('status_ids')
                    ->label('Статусы')
                    ->multiple()
                    ->searchable()
                    ->options(function () {
                        return \App\Models\ApplicationStatus::getActiveStatuses()
                            ->pluck('name', 'id')
                            ->toArray();
                    }),
            ])
            ->query(function (Builder $query, array $data): Builder {
                $filterService = app(CalendarFilterService::class);
                $user = auth()->user();
                $filters = [
                    'date_from' => $data['date_from'] ?? null,
                    'date_to' => $data['date_to'] ?? null,
                    'clinic_ids' => $data['clinic_ids'] ?? [],
                    'branch_ids' => $data['branch_ids'] ?? [],
                    'doctor_ids' => $data['doctor_ids'] ?? [],
                    'status_ids' => $data['status_ids'] ?? [],
                ];

                return $filterService->applyApplicationFilters($query, $filters, $user);
            });
    }
}
