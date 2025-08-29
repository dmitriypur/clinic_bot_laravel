<?php

namespace App\Filament\Resources\DoctorResource\Pages;

use App\Filament\Resources\DoctorResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateDoctor extends CreateRecord
{
    protected static string $resource = DoctorResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function afterCreate(): void
    {
        // Сохраняем связи с филиалами после создания врача
        $branchIds = $this->data['branch_ids'] ?? [];
        if (!empty($branchIds)) {
            $this->record->branches()->sync($branchIds);
        }
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Убираем branch_ids из основных данных, чтобы избежать ошибок
        unset($data['branch_ids']);
        return $data;
    }
}
