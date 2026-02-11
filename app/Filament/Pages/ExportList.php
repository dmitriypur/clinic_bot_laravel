<?php

namespace App\Filament\Pages;

use App\Models\Export;
use Filament\Pages\Page;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class ExportList extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $navigationIcon = "heroicon-o-arrow-down-tray";

    protected static ?string $navigationLabel = "Экспорты";

    protected static ?string $title = "Список экспортов";

    protected static ?string $navigationGroup = "Журнал приемов";

    protected static ?int $navigationSort = 7;

    protected static string $view = "filament.pages.export-list";

    public function table(Table $table): Table
    {
        return $table
            ->query(function () {
                $query = Export::query()->orderBy("created_at", "desc");

                $user = Auth::user();

                if ($user && ! $user->hasRole("super_admin")) {
                    $query->where("user_id", $user->id);
                }

                return $query;
            })
            ->columns([
                TextColumn::make("id")->label("ID")->sortable(),
                TextColumn::make("file_name")->label("Имя файла")->searchable(),
                TextColumn::make("exporter")
                    ->label("Источник")
                    ->formatStateUsing(function ($state) {
                        // Определяем источник по классу экспортера
                        if (str_contains($state, "ApplicationExporter")) {
                            return "Заявки/Записи на прием";
                        }

                        return class_basename($state);
                    }),
                TextColumn::make("successful_rows")
                    ->label("Успешно")
                    ->sortable(),
                TextColumn::make("total_rows")->label("Всего")->sortable(),
                TextColumn::make("completed_at")
                    ->label("Завершен")
                    ->dateTime("d.m.Y H:i")
                    ->sortable(),
                TextColumn::make("created_at")
                    ->label("Создан")
                    ->dateTime("d.m.Y H:i")
                    ->sortable(),
            ])
            ->actions([
                Action::make("download")
                    ->label("Скачать")
                    ->icon("heroicon-o-arrow-down-tray")
                    ->url(
                        fn($record) => route("export.download", [
                            "exportId" => $record->id,
                        ]),
                    )
                    ->openUrlInNewTab()
                    ->visible(fn($record) => $record->completed_at !== null)
                    ->color("success"),
                Action::make("delete")
                    ->label("Удалить")
                    ->icon("heroicon-o-trash")
                    ->color("danger")
                    ->requiresConfirmation()
                    ->modalHeading("Удаление экспорта")
                    ->modalDescription(
                        "Вы уверены, что хотите удалить этот экспорт? Файл также будет удален с сервера.",
                    )
                    ->modalSubmitActionLabel("Да, удалить")
                    ->action(function ($record) {
                        // Удаляем файлы с диска
                        $filePath = "filament_exports/{$record->id}/{$record->file_name}.xlsx";
                        if (
                            \Illuminate\Support\Facades\Storage::disk(
                                $record->file_disk,
                            )->exists($filePath)
                        ) {
                            \Illuminate\Support\Facades\Storage::disk(
                                $record->file_disk,
                            )->deleteDirectory(
                                "filament_exports/{$record->id}",
                            );
                        }

                        // Удаляем запись из базы данных
                        $record->delete();
                    }),
            ])
            ->bulkActions([
                BulkAction::make("delete_selected")
                    ->label("Удалить выбранные")
                    ->icon("heroicon-o-trash")
                    ->color("danger")
                    ->requiresConfirmation()
                    ->modalHeading("Удаление экспортов")
                    ->modalDescription(
                        "Вы уверены, что хотите удалить выбранные экспорты? Файлы также будут удалены с сервера.",
                    )
                    ->modalSubmitActionLabel("Да, удалить")
                    ->action(function ($records) {
                        foreach ($records as $record) {
                            // Удаляем файлы с диска
                            $filePath = "filament_exports/{$record->id}/{$record->file_name}.xlsx";
                            if (
                                \Illuminate\Support\Facades\Storage::disk(
                                    $record->file_disk,
                                )->exists($filePath)
                            ) {
                                \Illuminate\Support\Facades\Storage::disk(
                                    $record->file_disk,
                                )->deleteDirectory(
                                    "filament_exports/{$record->id}",
                                );
                            }

                            // Удаляем запись из базы данных
                            $record->delete();
                        }
                    }),
            ])
            ->defaultSort("created_at", "desc");
    }
}
