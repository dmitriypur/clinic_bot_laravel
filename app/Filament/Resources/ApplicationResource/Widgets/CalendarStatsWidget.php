<?php

namespace App\Filament\Resources\ApplicationResource\Widgets;

use App\Models\Application;
use App\Services\CalendarFilterService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Carbon\Carbon;

class CalendarStatsWidget extends BaseWidget
{
    protected static ?string $pollingInterval = null;
    
    protected CalendarFilterService $filterService;
    
    public function mount()
    {
        $this->filterService = app(CalendarFilterService::class);
    }

    protected function getStats(): array
    {
        $user = auth()->user();
        $today = Carbon::today();
        
        // Базовые фильтры для сегодняшнего дня
        $baseFilters = [
            'date_from' => $today->format('Y-m-d'),
            'date_to' => $today->format('Y-m-d'),
        ];
        
        // Заявки на сегодня
        $todayApplicationsQuery = Application::query();
        $this->filterService->applyApplicationFilters($todayApplicationsQuery, $baseFilters, $user);
        $todayApplications = $todayApplicationsQuery->count();
        
        // Заявки на завтра
        $tomorrow = $today->copy()->addDay();
        $tomorrowFilters = [
            'date_from' => $tomorrow->format('Y-m-d'),
            'date_to' => $tomorrow->format('Y-m-d'),
        ];
        
        $tomorrowApplicationsQuery = Application::query();
        $this->filterService->applyApplicationFilters($tomorrowApplicationsQuery, $tomorrowFilters, $user);
        $tomorrowApplications = $tomorrowApplicationsQuery->count();
        
        // Заявки на неделю
        $weekEnd = $today->copy()->addWeek();
        $weekFilters = [
            'date_from' => $today->format('Y-m-d'),
            'date_to' => $weekEnd->format('Y-m-d'),
        ];
        
        $weekApplicationsQuery = Application::query();
        $this->filterService->applyApplicationFilters($weekApplicationsQuery, $weekFilters, $user);
        $weekApplications = $weekApplicationsQuery->count();
        
        // Статистика по статусам заявок
        $completedQuery = Application::query();
        $this->filterService->applyApplicationFilters($completedQuery, $baseFilters, $user);
        $completedToday = $completedQuery->where('appointment_datetime', '<', now())->count();
        
        $pendingQuery = Application::query();
        $this->filterService->applyApplicationFilters($pendingQuery, $baseFilters, $user);
        $pendingToday = $pendingQuery->where('appointment_datetime', '>=', now())->count();

        return [
            Stat::make('Заявки сегодня', $todayApplications)
                ->description('Запланированные на сегодня')
                ->descriptionIcon('heroicon-m-calendar')
                ->color($todayApplications > 0 ? 'success' : 'gray'),
                
            Stat::make('Заявки завтра', $tomorrowApplications)
                ->description('Запланированные на завтра')
                ->descriptionIcon('heroicon-m-calendar-days')
                ->color($tomorrowApplications > 0 ? 'info' : 'gray'),
                
            Stat::make('Заявки на неделю', $weekApplications)
                ->description('Запланированные на неделю')
                ->descriptionIcon('heroicon-m-calendar-week')
                ->color($weekApplications > 0 ? 'warning' : 'gray'),
                
            Stat::make('Завершенные сегодня', $completedToday)
                ->description('Уже проведенные приемы')
                ->descriptionIcon('heroicon-m-check-circle')
                ->color('success'),
                
            Stat::make('Ожидающие сегодня', $pendingToday)
                ->description('Еще не проведенные приемы')
                ->descriptionIcon('heroicon-m-clock')
                ->color('warning'),
        ];
    }
}
