<?php

namespace App\Filament\Resources\ApplicationStatusResource\Pages;

use App\Filament\Resources\ApplicationStatusResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListApplicationStatuses extends ListRecords
{
    protected static string $resource = ApplicationStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
