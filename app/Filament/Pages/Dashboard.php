<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AllCabinetsScheduleWidget;
use App\Filament\Widgets\AppointmentCalendarWidget;
use App\Models\SystemSetting;
use Filament\Pages\Page;

class Dashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    
    protected static string $view = 'filament.pages.dashboard';
    
    protected static ?string $title = 'Дашборд';
    
    protected static ?string $navigationLabel = 'Дашборд';
    
    protected static ?int $navigationSort = 1;
    
    /**
     * Флаг включения календаря заявок
     */
    public bool $isCalendarEnabled = true;
    
    /**
     * Активный таб по умолчанию
     */
    public ?string $activeTab = 'appointments';

    /**
     * Получение виджетов для страницы
     */
    public function getWidgets(): array
    {
        $widgets = [
            AllCabinetsScheduleWidget::class,
        ];

        if (SystemSetting::getValue('dashboard_calendar_enabled', true)) {
            array_unshift($widgets, AppointmentCalendarWidget::class);
        }

        return $widgets;
    }

    /**
     * Инициализация активного таба
     */
    public function mount(): void
    {
        $this->isCalendarEnabled = SystemSetting::getValue('dashboard_calendar_enabled', true);

        if (!$this->isCalendarEnabled) {
            $this->activeTab = 'schedule';
        } elseif (!$this->activeTab) {
            $this->activeTab = 'appointments';
        }
    }

    public function updatedIsCalendarEnabled($value): void
    {
        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $enabled = $enabled ?? false;

        SystemSetting::setValue('dashboard_calendar_enabled', $enabled);

        if (!$enabled) {
            $this->activeTab = 'schedule';
        } elseif ($this->activeTab !== 'appointments') {
            $this->activeTab = 'appointments';
        }

        $this->isCalendarEnabled = $enabled;
    }

}
