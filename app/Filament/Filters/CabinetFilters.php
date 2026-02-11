<?php

namespace App\Filament\Filters;

use App\Services\CalendarFilterService;
use Filament\Forms\Components\Select;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class CabinetFilters extends Filter
{
    public static function make(?string $name = null): static
    {
        return parent::make($name ?? 'cabinet_filters')
            ->label('🔍 Фильтры кабинетов')
            ->form([
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
                    }),
            ])
            ->query(function (Builder $query, array $data): Builder {
                // Фильтры по клиникам
                if (! empty($data['clinic_ids'])) {
                    $query->whereHas('branch', function ($q) use ($data) {
                        $q->whereIn('clinic_id', $data['clinic_ids']);
                    });
                }

                // Фильтры по филиалам
                if (! empty($data['branch_ids'])) {
                    $query->whereIn('branch_id', $data['branch_ids']);
                }

                return $query;
            });
    }
}
