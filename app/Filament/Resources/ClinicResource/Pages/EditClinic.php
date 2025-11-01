<?php

namespace App\Filament\Resources\ClinicResource\Pages;

use App\Filament\Resources\ClinicResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditClinic extends EditRecord
{
    protected static string $resource = ClinicResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);

        // Проверяем права доступа
        $user = auth()->user();

        if (! $user || ! $user->can('update_clinic')) {
            abort(403, 'У вас нет прав для редактирования клиник');
        }

        // Если пользователь - партнер, проверяем, что он редактирует свою клинику
        if ($user->hasRole('partner') && $this->record->id !== $user->clinic_id) {
            abort(403, 'Вы можете редактировать только свою клинику');
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(function (): bool {
                    $user = auth()->user();

                    if (! $user) {
                        return false;
                    }

                    if ($user->hasRole('partner')) {
                        return $user->can('delete_clinic') && $this->record->id === $user->clinic_id;
                    }

                    return $user->can('delete_clinic');
                }),
        ];
    }
}
