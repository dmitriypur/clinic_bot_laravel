<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Models\Export;

class ExportDownloadController extends Controller
{
    public function download($exportId)
    {
        $export = Export::find($exportId);
        
        if (!$export) {
            abort(404, 'Экспорт не найден');
        }
        
        if (!$export->completed_at) {
            abort(404, 'Экспорт еще не завершен');
        }
        
        $filePath = "filament_exports/{$exportId}/{$export->file_name}.xlsx";
        
        if (!Storage::disk($export->file_disk)->exists($filePath)) {
            abort(404, 'Файл не найден');
        }
        
        return Storage::disk($export->file_disk)->download($filePath, "{$export->file_name}.xlsx");
    }
}
