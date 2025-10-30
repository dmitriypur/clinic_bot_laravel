<?php

namespace App\Filament\Resources\CityResource\Pages;

use App\Filament\Resources\CityResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateCity extends CreateRecord
{
    protected static string $resource = CityResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['name'] = trim($data['name']);

        return $data;
    }

    protected function afterCreate(): void
    {
        parent::afterCreate();

        $user = auth()->user();

        if ($user && $user->hasRole('partner') && $user->clinic_id) {
            $this->record->clinics()->syncWithoutDetaching([$user->clinic_id]);
        }
    }
}
