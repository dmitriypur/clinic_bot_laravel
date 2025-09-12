<?php

namespace App\Filament\Resources\BidResource\Pages;

use App\Filament\Resources\BidResource;
use App\Filament\Widgets\AppointmentCalendarWidget;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditBid extends EditRecord
{
    protected static string $resource = BidResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            AppointmentCalendarWidget::class,
        ];
    }
}
