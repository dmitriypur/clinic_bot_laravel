<?php

namespace App\Filament\Resources\CabinetResource\Pages;

use App\Filament\Resources\CabinetResource;
use App\Filament\Widgets\AllCabinetsScheduleWidget;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCabinets extends ListRecords
{
    protected static string $resource = CabinetResource::class;
    
    /**
     * Кастомный view для отображения табов
     */
    protected static string $view = 'filament.resources.cabinet-resource.pages.list-cabinets';
    
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
            AllCabinetsScheduleWidget::class,
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
