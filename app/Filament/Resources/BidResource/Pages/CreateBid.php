<?php

namespace App\Filament\Resources\BidResource\Pages;

use App\Filament\Resources\BidResource;
use App\Filament\Widgets\AppointmentCalendarWidget;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateBid extends CreateRecord
{
    protected static string $resource = BidResource::class;

    protected function getFooterWidgets(): array
    {
        return [
            AppointmentCalendarWidget::class,
        ];
    }
}
