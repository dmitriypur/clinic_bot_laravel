<?php

namespace App\Filament\Resources\CabinetResource\Pages;

use App\Filament\Resources\CabinetResource;
use App\Filament\Widgets\CabinetScheduleWidget;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewCabinet extends ViewRecord
{
    protected static string $resource = CabinetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CabinetScheduleWidget::class,
        ];
    }
}
