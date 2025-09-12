<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use App\Filament\Widgets\AppointmentCalendarWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListApplications extends ListRecords
{
    protected static string $resource = ApplicationResource::class;
    
    /**
     * Кастомный view для отображения табов
     */
    protected static string $view = 'filament.resources.application-resource.pages.list-applications';
    
    /**
     * Активный таб по умолчанию
     */
    public ?string $activeTab = 'calendar';

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    /**
     * Получение виджетов для страницы
     */
    public function getWidgets(): array
    {
        return [
            AppointmentCalendarWidget::class,
        ];
    }

    /**
     * Инициализация активного таба
     */
    public function mount(): void
    {
        parent::mount();
        
        // Устанавливаем активный таб по умолчанию
        if (!$this->activeTab) {
            $this->activeTab = 'calendar';
        }
    }
}
