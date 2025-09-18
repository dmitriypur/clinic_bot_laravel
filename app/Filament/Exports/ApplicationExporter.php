<?php

namespace App\Filament\Exports;

use App\Models\Application;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Exports\Enums\ExportFormat;

class ApplicationExporter extends Exporter
{
    protected static ?string $model = Application::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),
            ExportColumn::make('city.name')->label('Город'),
            ExportColumn::make('clinic.name')->label('Клиника'),
            ExportColumn::make('doctor.full_name')->label('Врач'),
            ExportColumn::make('full_name_parent')->label('Имя родителя'),
            ExportColumn::make('full_name')->label('Имя ребенка'),
            ExportColumn::make('birth_date')->label('Дата рождения'),
            ExportColumn::make('phone')->label('Телефон'),
            ExportColumn::make('promo_code')->label('Промокод'),
            ExportColumn::make('created_at')->label('Дата создания'),
            ExportColumn::make('updated_at')->label('Дата обновления'),
            ExportColumn::make('branch.name')->label('Филиал'),
            ExportColumn::make('appointment_datetime')->label('Дата и время приема'),
            ExportColumn::make('cabinet.name')->label('Кабинет'),
        ];
    }

    public function getFormats(): array
    {
        return [
            ExportFormat::Xlsx,
            ExportFormat::Csv,
        ];
    }

    public static function getCsvDelimiter(): string
    {
        return ';';
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $successfulRows = $export->successful_rows;
        $rowsWord = $successfulRows === 1 ? 'запись' : ($successfulRows < 5 ? 'записи' : 'записей');
        
        // Определяем тип экспорта по контексту
        $exportType = 'заявок';
        if (request()->is('admin/applications*')) {
            $exportType = 'записей на прием';
        } elseif (request()->is('admin/bids*')) {
            $exportType = 'заявок';
        }
        
        $body = "Экспорт {$exportType} завершен. Экспортировано {$successfulRows} {$rowsWord}.";

        $failedRowsCount = $export->getFailedRowsCount();
        if ($failedRowsCount > 0) {
            $failedRowsWord = $failedRowsCount === 1 ? 'запись' : ($failedRowsCount < 5 ? 'записи' : 'записей');
            $body .= " Не удалось экспортировать {$failedRowsCount} {$failedRowsWord}.";
        }

        // Добавляем ссылку на скачивание
        $downloadUrl = route('export.download', ['exportId' => $export->id]);
        $body .= " Ссылка для скачивания: {$downloadUrl}";

        return $body;
    }
}
