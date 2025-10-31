<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        if (! auth()->user()?->hasRole('super_admin')) {
            return [];
        }

        return [Actions\CreateAction::make()];
    }

    protected function getTableBulkActions(): array
    {
        if (! auth()->user()?->hasRole('super_admin')) {
            return [];
        }

        return parent::getTableBulkActions();
    }
}
