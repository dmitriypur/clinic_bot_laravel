<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AllCabinetsScheduleWidget;
use App\Filament\Widgets\AppointmentCalendarWidget;
use Filament\Pages\Page;

class Dashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-home';
    
    protected static string $view = 'filament.pages.dashboard';
    
    protected static ?string $title = 'Дашборд';
    
    protected static ?string $navigationLabel = 'Дашборд';
    
    protected static ?int $navigationSort = 1;
    
    /**
     * Активный таб по умолчанию
     */
    public ?string $activeTab = 'appointments';

    /**
     * Получение виджетов для страницы
     */
    public function getWidgets(): array
    {
        return [
            AppointmentCalendarWidget::class,
            AllCabinetsScheduleWidget::class,
        ];
    }

    /**
     * Инициализация активного таба
     */
    public function mount(): void
    {
        // Устанавливаем активный таб по умолчанию
        if (!$this->activeTab) {
            $this->activeTab = 'appointments';
        }
    }

}
