<?php

namespace App\Http\Controllers;

use App\Models\Export;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Auth;

class ExportDownloadController extends Controller
{
    public function download($exportId)
    {
        $export = Export::find($exportId);

        if (! $export) {
            abort(404, 'Экспорт не найден');
        }

        $user = Auth::user();

        if (! $user) {
            abort(403, 'Доступ запрещен');
        }

        if (! $user->hasRole('super_admin') && $export->user_id !== $user->id) {
            abort(403, 'Доступ запрещен');
        }

        if (! $export->completed_at) {
            abort(404, 'Экспорт еще не завершен');
        }

        $filePath = "filament_exports/{$exportId}/{$export->file_name}.xlsx";

        if (! Storage::disk($export->file_disk)->exists($filePath)) {
            abort(404, 'Файл не найден');
        }

        return Storage::disk($export->file_disk)->download($filePath, "{$export->file_name}.xlsx");
    }
}
