<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Export;
use Illuminate\Support\Facades\Storage;

class CleanupOldExports extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exports:cleanup {--days=30 : Количество дней для хранения экспортов}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Удаляет старые экспорты и их файлы';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $days = $this->option('days');
        $cutoffDate = now()->subDays($days);
        
        $this->info("Удаление экспортов старше {$days} дней (до {$cutoffDate->format('d.m.Y H:i')})");
        
        $oldExports = Export::where('created_at', '<', $cutoffDate)->get();
        
        if ($oldExports->isEmpty()) {
            $this->info('Старые экспорты не найдены.');
            return;
        }
        
        $deletedCount = 0;
        $deletedFiles = 0;
        
        foreach ($oldExports as $export) {
            // Удаляем файлы с диска
            $filePath = "filament_exports/{$export->id}/{$export->file_name}.xlsx";
            if (Storage::disk($export->file_disk)->exists($filePath)) {
                Storage::disk($export->file_disk)->deleteDirectory("filament_exports/{$export->id}");
                $deletedFiles++;
            }
            
            // Удаляем запись из базы данных
            $export->delete();
            $deletedCount++;
            
            $this->line("Удален экспорт ID: {$export->id} ({$export->file_name})");
        }
        
        $this->info("Удалено экспортов: {$deletedCount}");
        $this->info("Удалено папок с файлами: {$deletedFiles}");
    }
}
