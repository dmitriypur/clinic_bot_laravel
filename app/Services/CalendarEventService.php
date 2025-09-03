<?php

namespace App\Services;

use App\Models\Application;
use App\Models\DoctorShift;
use App\Models\User;
use App\Traits\HasCalendarOptimizations;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CalendarEventService
{
    use HasCalendarOptimizations;
    
    protected CalendarFilterService $filterService;
    
    public function __construct(CalendarFilterService $filterService)
    {
        $this->filterService = $filterService;
    }

    /**
     * Генерирует события для календаря на основе смен врачей
     */
    public function generateEvents(array $fetchInfo, array $filters, User $user): array
    {
        $events = [];
        
        // Получаем смены с применением фильтров и оптимизаций
        $shiftsQuery = DoctorShift::query()
            ->with(['doctor', 'cabinet.branch.clinic', 'cabinet.branch.city'])
            ->optimizedDateRange(
                Carbon::parse($fetchInfo['start']), 
                Carbon::parse($fetchInfo['end'])
            );
            
        $this->filterService->applyShiftFilters($shiftsQuery, $filters, $user);
        $shifts = $shiftsQuery->get();

        // Обрабатываем каждую смену
        foreach ($shifts as $shift) {
            $events = array_merge($events, $this->generateShiftEvents($shift, $user));
        }

        return $events;
    }

    /**
     * Генерирует события для одной смены
     */
    private function generateShiftEvents(DoctorShift $shift, User $user): array
    {
        $events = [];
        $slotDuration = $shift->getEffectiveSlotDuration();
        $slots = $shift->getTimeSlots();
        
        foreach ($slots as $slot) {
            $isOccupied = $this->isSlotOccupied($shift->cabinet_id, $slot['start'], $user);
            $application = $this->getSlotApplication($shift->cabinet_id, $slot['start'], $user);
            
            $events[] = $this->createEventData($shift, $slot, $isOccupied, $application);
        }
        
        return $events;
    }

    /**
     * Проверяет занятость слота
     */
    private function isSlotOccupied(int $cabinetId, Carbon $slotStart, User $user): bool
    {
        $query = Application::query()
            ->where('cabinet_id', $cabinetId)
            ->where('appointment_datetime', $slotStart);
            
        // Применяем только фильтрацию по ролям, без дополнительных фильтров
        if ($user->isPartner()) {
            $query->where('clinic_id', $user->clinic_id);
        } elseif ($user->isDoctor()) {
            $query->where('doctor_id', $user->doctor_id);
        }
        
        return $query->exists();
    }

    /**
     * Получает заявку в слоте
     */
    private function getSlotApplication(int $cabinetId, Carbon $slotStart, User $user): ?Application
    {
        $query = Application::query()
            ->with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
            ->where('cabinet_id', $cabinetId)
            ->where('appointment_datetime', $slotStart);
            
        // Применяем только фильтрацию по ролям, без дополнительных фильтров
        if ($user->isPartner()) {
            $query->where('clinic_id', $user->clinic_id);
        } elseif ($user->isDoctor()) {
            $query->where('doctor_id', $user->doctor_id);
        }
        
        return $query->first();
    }

    /**
     * Создает данные события для календаря
     */
    private function createEventData(DoctorShift $shift, array $slot, bool $isOccupied, ?Application $application): array
    {
        $config = config('calendar');
        $backgroundColor = $isOccupied ? $config['colors']['occupied_slot'] : $config['colors']['free_slot'];
        $title = $isOccupied ? ($application ? $application->full_name : 'Занят') : 'Свободен';
        
        return [
            'id' => 'slot_' . $shift->id . '_' . $slot['start']->format('Y-m-d_H-i'),
            'title' => $title,
            'start' => $slot['start'],
            'end' => $slot['end'],
            'backgroundColor' => $backgroundColor,
            'borderColor' => $backgroundColor,
            'extendedProps' => [
                'shift_id' => $shift->id,
                'cabinet_id' => $shift->cabinet_id,
                'doctor_id' => $shift->doctor_id,
                'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен',
                'cabinet_name' => $shift->cabinet->name ?? 'Кабинет не указан',
                'branch_name' => $shift->cabinet->branch->name ?? 'Филиал не указан',
                'clinic_name' => $shift->cabinet->branch->clinic->name ?? 'Клиника не указана',
                'is_occupied' => $isOccupied,
                'slot_start' => $slot['start'],
                'slot_end' => $slot['end'],
                'application_id' => $application ? $application->id : null,
                'application_data' => $application ? [
                    'full_name' => $application->full_name,
                    'phone' => $application->phone,
                    'full_name_parent' => $application->full_name_parent,
                    'birth_date' => $application->birth_date,
                    'promo_code' => $application->promo_code,
                ] : null,
            ]
        ];
    }

    /**
     * Получает статистику по событиям
     */
    public function getEventStats(array $events): array
    {
        $totalSlots = count($events);
        $occupiedSlots = collect($events)->where('extendedProps.is_occupied', true)->count();
        $freeSlots = $totalSlots - $occupiedSlots;
        
        return [
            'total' => $totalSlots,
            'occupied' => $occupiedSlots,
            'free' => $freeSlots,
            'occupancy_rate' => $totalSlots > 0 ? round(($occupiedSlots / $totalSlots) * 100, 2) : 0,
        ];
    }

    /**
     * Группирует события по дням
     */
    public function groupEventsByDay(array $events): array
    {
        return collect($events)
            ->groupBy(function ($event) {
                return Carbon::parse($event['start'])->format('Y-m-d');
            })
            ->map(function ($dayEvents) {
                return [
                    'date' => Carbon::parse($dayEvents->first()['start'])->format('Y-m-d'),
                    'events' => $dayEvents->toArray(),
                    'stats' => $this->getEventStats($dayEvents->toArray()),
                ];
            })
            ->toArray();
    }

    /**
     * Фильтрует события по типу (занятые/свободные)
     */
    public function filterEventsByType(array $events, string $type): array
    {
        return collect($events)
            ->filter(function ($event) use ($type) {
                return $type === 'occupied' ? $event['extendedProps']['is_occupied'] : !$event['extendedProps']['is_occupied'];
            })
            ->toArray();
    }
}
