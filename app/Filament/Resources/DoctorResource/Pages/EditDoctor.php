<?php

namespace App\Filament\Resources\DoctorResource\Pages;

use App\Filament\Resources\DoctorResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDoctor extends EditRecord
{
    protected static string $resource = DoctorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Загружаем текущие филиалы врача для отображения в форме
        $data['branch_ids'] = $this->record->branches()->pluck('branches.id')->toArray();

        return $data;
    }

    protected function afterSave(): void
    {
        // Сохраняем связи с филиалами после обновления врача
        $branchIds = $this->data['branch_ids'] ?? [];
        $this->record->branches()->sync($branchIds);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Убираем branch_ids из основных данных, чтобы избежать ошибок
        unset($data['branch_ids']);

        return $data;
    }
}
