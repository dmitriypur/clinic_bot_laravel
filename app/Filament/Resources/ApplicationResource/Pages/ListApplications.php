<?php

namespace App\Filament\Resources\ApplicationResource\Pages;

use App\Filament\Resources\ApplicationResource;
use App\Filament\Widgets\AppointmentCalendarWidget;
use App\Models\SystemSetting;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListApplications extends ListRecords
{
    protected static string $resource = ApplicationResource::class;
    
    /**
     * Кастомный view для отображения табов
     */
    protected static string $view = 'filament.resources.application-resource.pages.list-applications';
    
    public bool $isCalendarEnabled = true;
    
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
        if (SystemSetting::getValue('dashboard_calendar_enabled', true)) {
            return [
                AppointmentCalendarWidget::class,
            ];
        }

        return [];
    }

    /**
     * Инициализация активного таба
     */
    public function mount(): void
    {
        parent::mount();
        $this->isCalendarEnabled = SystemSetting::getValue('dashboard_calendar_enabled', true);

        if (!$this->isCalendarEnabled) {
            $this->activeTab = 'list';
        } elseif (!$this->activeTab) {
            $this->activeTab = 'calendar';
        }
    }

}
