<?php

namespace App\Filament\Resources\CabinetResource\Pages;

use App\Filament\Resources\CabinetResource;
use App\Filament\Widgets\CabinetScheduleWidget;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

/**
 * Страница просмотра кабинета
 * 
 * Отображает информацию о кабинете и включает виджет календаря расписания.
 * Позволяет просматривать и управлять сменами врачей в конкретном кабинете.
 */
class ViewCabinet extends ViewRecord
{
    protected static string $resource = CabinetResource::class;

    /**
     * Действия в заголовке страницы
     * Позволяет редактировать кабинет
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),  // Кнопка редактирования кабинета
        ];
    }

    /**
     * Виджеты в заголовке страницы
     * Включает календарь расписания для кабинета
     */
    protected function getHeaderWidgets(): array
    {
        return [
            CabinetScheduleWidget::class,  // Виджет календаря расписания
        ];
    }
}
