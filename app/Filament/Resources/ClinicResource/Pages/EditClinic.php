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
        if (!$user->hasRole('super_admin') && !$user->hasRole('partner')) {
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
                ->visible(fn () => auth()->user()->hasRole('super_admin') || 
                    (auth()->user()->hasRole('partner') && $this->record->id === auth()->user()->clinic_id)),
        ];
    }
}
