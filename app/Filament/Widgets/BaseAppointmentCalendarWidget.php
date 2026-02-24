<?php

namespace App\Filament\Widgets;

use App\Models\Application;
use App\Services\CalendarEventService;
use App\Services\CalendarFilterService;
use Illuminate\Support\Facades\Auth;
use Saade\FilamentFullCalendar\Widgets\FullCalendarWidget;

abstract class BaseAppointmentCalendarWidget extends FullCalendarWidget
{
    public \Illuminate\Database\Eloquent\Model|string|null $model = Application::class;

    /**
     * Данные выбранного слота, переиспользуются дочерними виджетами.
     */
    public array $slotData = [];

    protected ?CalendarFilterService $filterService = null;

    protected ?CalendarEventService $eventService = null;

    protected function getFilterService(): CalendarFilterService
    {
        if ($this->filterService === null) {
            $this->filterService = app(CalendarFilterService::class);
        }

        return $this->filterService;
    }

    protected function getEventService(): CalendarEventService
    {
        if ($this->eventService === null) {
            $this->eventService = app(CalendarEventService::class);
        }

        return $this->eventService;
    }

    protected function makeAppointmentCalendarConfig(array $overrides = []): array
    {
        $defaults = [
            'firstDay' => 1,
            'headerToolbar' => [
                'left' => 'prev,next today',
                'center' => 'title',
                'right' => 'dayGridMonth,timeGridWeek,timeGridDay,listWeek',
            ],
            'initialView' => 'timeGridWeek',
            'navLinks' => true,
            'editable' => false,
            'selectable' => false,
            'selectMirror' => false,
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
            'snapDuration' => '00:05:00',
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

    protected function generateCalendarEvents(array $fetchInfo, array $filters = [], ?\Illuminate\Contracts\Auth\Authenticatable $user = null): array
    {
        $user = $user ?: Auth::user();

        if (! $user) {
            return [];
        }

        $fetchInfo = $this->prepareFetchInfo($fetchInfo);

        return $this->getEventService()->generateEvents($fetchInfo, $filters, $user);
    }

    protected function prepareFetchInfo(array $fetchInfo): array
    {
        return $fetchInfo;
    }
}
