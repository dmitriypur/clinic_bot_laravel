<?php

namespace App\Filament\Pages;

use App\Services\DatabaseBackupService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class DatabaseBackups extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-circle-stack';

    protected static ?string $navigationLabel = 'Резервные копии БД';

    protected static ?string $title = 'Резервные копии БД';

    protected static ?string $navigationGroup = 'Система';

    protected static ?int $navigationSort = 99;

    protected static string $view = 'filament.pages.database-backups';

    public array $backups = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public function mount(DatabaseBackupService $backupService): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $this->backups = $backupService->list();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createBackup')
                ->label('Создать дамп')
                ->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()
                ->modalHeading('Создать дамп базы данных')
                ->modalDescription('Файл будет сохранен в защищенном storage и доступен для скачивания только super_admin.')
                ->action(function (DatabaseBackupService $backupService): void {
                    $backupService->create();
                    $this->backups = $backupService->list();

                    Notification::make()
                        ->title('Дамп БД создан')
                        ->success()
                        ->send();
                }),
        ];
    }

    public function downloadUrl(string $path): string
    {
        return route('database-backups.download', ['path' => $path]);
    }

    public function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 2).' МБ';
        }

        return round($bytes / 1024, 2).' КБ';
    }
}
