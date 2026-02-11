<?php

namespace App\Filament\Resources\BranchResource\Pages;

use App\Filament\Resources\BranchResource;
use App\Models\OnecSlot;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditBranch extends EditRecord
{
    protected static string $resource = BranchResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('clear-onec-slots')
                ->label('Очистить слоты 1С')
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => (bool) $this->record)
                ->action(function (): void {
                    if (! $this->record) {
                        return;
                    }

                    $deleted = OnecSlot::query()
                        ->where('branch_id', $this->record->id)
                        ->delete();

                    Notification::make()
                        ->title('Слоты удалены')
                        ->body("Удалено {$deleted} записей.")
                        ->success()
                        ->send();
                }),
            Actions\DeleteAction::make(),
        ];
    }
}
