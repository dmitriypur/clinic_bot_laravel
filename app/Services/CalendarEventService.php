<?php

namespace App\Services;

use App\Models\Application;
use App\Models\DoctorShift;
use App\Models\User;
use App\Services\TimezoneService;
use App\Traits\HasCalendarOptimizations;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CalendarEventService
{
    use HasCalendarOptimizations;
    
    protected CalendarFilterService $filterService;
    protected TimezoneService $timezoneService;
    
    public function __construct(CalendarFilterService $filterService, TimezoneService $timezoneService)
    {
        $this->filterService = $filterService;
        $this->timezoneService = $timezoneService;
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
        // Для MySQL: используем время слота как есть, так как в базе хранится локальное время
        // Для SQLite: конвертируем UTC в локальное время
        $slotStartForQuery = config('database.default') === 'mysql' 
            ? $slotStart->format('Y-m-d H:i:s')
            : $slotStart->setTimezone(config('app.timezone', 'UTC'));
        
        $application = Application::query()
            ->where('cabinet_id', $cabinetId)
            ->where('appointment_datetime', $slotStartForQuery)
            ->first();
            
        if (!$application) {
            return false;
        }
        
        // Проверяем права доступа
        if ($user->isPartner() && $application->clinic_id !== $user->clinic_id) {
            return false; // Партнер не видит заявки из других клиник
        } elseif ($user->isDoctor() && $application->doctor_id !== $user->doctor_id) {
            return false; // Врач не видит заявки других врачей
        }
        
        return true;
    }

    /**
     * Получает заявку в слоте
     */
    private function getSlotApplication(int $cabinetId, Carbon $slotStart, User $user): ?Application
    {
        // Для MySQL: используем время слота как есть, так как в базе хранится локальное время
        // Для SQLite: конвертируем UTC в локальное время
        $slotStartForQuery = config('database.default') === 'mysql' 
            ? $slotStart->format('Y-m-d H:i:s')
            : $slotStart->setTimezone(config('app.timezone', 'UTC'));
        
        $query = Application::query()
            ->with(['city', 'clinic', 'branch', 'cabinet', 'doctor'])
            ->where('cabinet_id', $cabinetId)
            ->where('appointment_datetime', $slotStartForQuery);
            
        // Сначала ищем заявку без фильтрации по ролям
        $application = $query->first();
        
        \Log::info('Поиск заявки в слоте', [
            'cabinet_id' => $cabinetId,
            'slot_start_utc' => $slotStart,
            'slot_start_for_query' => $slotStartForQuery,
            'database_type' => config('database.default'),
            'user_id' => $user->id,
            'user_role' => $user->getRoleNames()->first(),
            'found_application_id' => $application ? $application->id : null,
            'sql_query' => $query->toSql(),
            'sql_bindings' => $query->getBindings()
        ]);
        
        if ($application) {
            // Проверяем права доступа после нахождения заявки
            if ($user->isPartner() && $application->clinic_id !== $user->clinic_id) {
                return null; // Партнер не имеет доступа к заявке из другой клиники
            } elseif ($user->isDoctor() && $application->doctor_id !== $user->doctor_id) {
                return null; // Врач не имеет доступа к заявке другого врача
            }
        }
        
        return $application;
    }

    /**
     * Создает данные события для календаря
     */
    private function createEventData(DoctorShift $shift, array $slot, bool $isOccupied, ?Application $application): array
    {
        $config = config('calendar');
        $slotStart = Carbon::parse($slot['start']);
        $slotEnd = Carbon::parse($slot['end']);
        
        // Получаем часовой пояс города филиала
        $cityId = $shift->cabinet->branch->city_id;
        $cityTimezone = $this->timezoneService->getCityTimezone($cityId);
        
        // Конвертируем время слота в часовой пояс города
        $slotStartInCity = $slotStart->setTimezone($cityTimezone);
        
        // Проверяем, прошло ли время в часовом поясе города
        $nowInCity = $this->timezoneService->nowInCityTimezone($cityId);
        $isPast = $slotStartInCity->isPast();
        
        
        // Определяем цвета в зависимости от времени, занятости и статуса приема
        if ($isPast) {
            // Для прошедших слотов используем серые цвета
            $backgroundColor = $isOccupied ? '#6B7280' : '#9CA3AF'; // темно-серый для занятых, светло-серый для свободных
        } else {
            // Для текущих/будущих слотов используем цвета в зависимости от статуса приема
            if ($isOccupied && $application) {
                switch ($application->appointment_status) {
                    case \App\Models\Application::STATUS_IN_PROGRESS:
                        $backgroundColor = '#3B82F6'; // Синий для приема в процессе
                        break;
                    case \App\Models\Application::STATUS_COMPLETED:
                        $backgroundColor = '#4a7b6c'; // Темно-зеленый для завершенного приема
                        break;
                    case \App\Models\Application::STATUS_SCHEDULED:
                    default:
                        $backgroundColor = $config['colors']['occupied_slot']; // Стандартный цвет для запланированного
                        break;
                }
            } else {
                $backgroundColor = $config['colors']['free_slot']; // Цвет для свободных слотов
            }
        }
        
        $title = $isOccupied ? ($application ? $application->full_name : 'Занят') : 'Свободен';
        
        // Отладочная информация для занятых слотов
        if ($isOccupied && $application) {
            \Log::info('Создаем событие для занятого слота', [
                'application_id' => $application->id,
                'cabinet_id' => $shift->cabinet_id,
                'slot_start' => $slot['start'],
                'application_clinic_id' => $application->clinic_id,
                'application_doctor_id' => $application->doctor_id,
                'application_appointment_datetime' => $application->appointment_datetime
            ]);
        } elseif ($isOccupied && !$application) {
            \Log::warning('Слот помечен как занятый, но заявка не найдена', [
                'cabinet_id' => $shift->cabinet_id,
                'slot_start' => $slot['start'],
                'shift_id' => $shift->id
            ]);
        }
        
        // Создаем текст подсказки
        $tooltipText = $shift->cabinet->name . "\n" .
                      "Филиал: " . ($shift->cabinet->branch->name ?? "Не указан") . "\n" .
                      "Клиника: " . ($shift->cabinet->branch->clinic->name ?? "Не указана") . "\n" .
                      "Врач: " . ($shift->doctor->full_name ?? "Не назначен");
        
        $eventData = [
            'id' => 'slot_' . $shift->id . '_' . $slot['start']->format('Y-m-d_H-i') . ($application ? '_app_' . $application->id : ''),
            'title' => $title,
            'start' => $slot['start'],
            'end' => $slot['end'],
            'backgroundColor' => $backgroundColor,
            'borderColor' => $backgroundColor,
            'classNames' => $isPast ? ['past-appointment'] : ['active-appointment'],
            'tooltip' => $tooltipText,
            'extendedProps' => [
                'shift_id' => $shift->id,
                'cabinet_id' => $shift->cabinet_id,
                'doctor_id' => $shift->doctor_id,
                'doctor_name' => $shift->doctor->full_name ?? 'Врач не назначен',
                'cabinet_name' => $shift->cabinet->name ?? 'Кабинет не указан',
                'branch_name' => $shift->cabinet->branch->name ?? 'Филиал не указан',
                'clinic_name' => $shift->cabinet->branch->clinic->name ?? 'Клиника не указана',
                'city_id' => $cityId,
                'city_timezone' => $cityTimezone,
                'is_occupied' => $isOccupied,
                'is_past' => $isPast,
                'slot_start' => $slot['start'],
                'slot_end' => $slot['end'],
                'slot_start_city_time' => $slotStartInCity->format('Y-m-d H:i:s'),
                'application_id' => $application ? $application->id : null,
                'application_status' => $application ? $application->appointment_status : null,
                'application_data' => $application ? [
                    'full_name' => $application->full_name,
                    'phone' => $application->phone,
                    'full_name_parent' => $application->full_name_parent,
                    'birth_date' => $application->birth_date,
                    'promo_code' => $application->promo_code,
                    'appointment_status' => $application->appointment_status,
                    'status_label' => $application->getStatusLabel(),
                ] : null,
            ]
        ];
        
        // Отладочная информация для занятых слотов
        if ($isOccupied && $application) {
                    \Log::info('Создано событие с extendedProps', [
            'event_id' => $eventData['id'],
            'application_id' => $eventData['extendedProps']['application_id'],
            'cabinet_id' => $eventData['extendedProps']['cabinet_id'],
            'slot_start' => $eventData['extendedProps']['slot_start']
        ]);
        
        // Дополнительное логирование для отладки
        \Log::info('Полные данные события', [
            'event_data' => $eventData
        ]);
        
        // Дополнительное логирование для отладки application_id
        if ($application) {
            \Log::info('Отладка application_id в событии', [
                'application_id_from_db' => $application->id,
                'application_id_in_event' => $eventData['extendedProps']['application_id'],
                'event_id' => $eventData['id'],
                'slot_start' => $slot['start']->format('Y-m-d H:i:s')
            ]);
        }
        }
        
        return $eventData;
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
