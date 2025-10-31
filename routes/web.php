<?php

use App\Http\Controllers\Bot\BotController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\Admin\CabinetShiftController;
use App\Http\Controllers\ExportDownloadController;

Route::get('/app', function () {
    return Inertia::render('Booking');
})->middleware('telegram.webapp');

// Telegram Bot webhook
Route::match(['get', 'post'], '/botman', [BotController::class, 'handle']);

// Export download
Route::get('/download/export/{exportId}', [ExportDownloadController::class, 'download'])
    ->name('export.download');

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    Route::get('cabinets/{cabinet}/shifts/events', [CabinetShiftController::class,'events'])
        ->name('admin.cabinet.shifts.events');

    Route::post('cabinets/{cabinet}/shifts', [CabinetShiftController::class,'store'])
        ->name('admin.cabinet.shifts.store');

    Route::patch('cabinets/{cabinet}/shifts/{shift}', [CabinetShiftController::class,'update'])
        ->name('admin.cabinet.shifts.update');

    Route::delete('cabinets/{cabinet}/shifts/{shift}', [CabinetShiftController::class,'destroy'])
        ->name('admin.cabinet.shifts.delete');
});
