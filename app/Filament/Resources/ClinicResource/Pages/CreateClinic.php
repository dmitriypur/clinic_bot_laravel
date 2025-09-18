<?php

namespace App\Filament\Resources\ClinicResource\Pages;

use App\Filament\Resources\ClinicResource;
use Filament\Actions;
use Filament\Resources\Pages\CreateRecord;

class CreateClinic extends CreateRecord
{
    protected static string $resource = ClinicResource::class;

    public function mount(): void
    {
        parent::mount();
        
        // Проверяем права доступа
        $user = auth()->user();
        if (!$user->hasRole('super_admin') && !$user->hasRole('partner')) {
            abort(403, 'У вас нет прав для создания клиник');
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
