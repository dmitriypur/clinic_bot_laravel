<?php

use App\Http\Controllers\Admin\CabinetShiftController;
use App\Http\Controllers\Bot\BotController;
use App\Http\Controllers\ExportDownloadController;
use App\Models\Branch;
use App\Models\OnecSlot;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/app', function () {
    return Inertia::render('Booking');
});

// Telegram Bot webhook
Route::match(['get', 'post'], '/botman', [BotController::class, 'handle']);

// Export download
Route::get('/download/export/{exportId}', [ExportDownloadController::class, 'download'])
    ->name('export.download')
    ->middleware(['auth']);

Route::middleware(['web', 'auth', 'verified'])->group(function () {
    Route::get('cabinets/{cabinet}/shifts/events', [CabinetShiftController::class, 'events'])
        ->name('admin.cabinet.shifts.events');

    Route::post('cabinets/{cabinet}/shifts', [CabinetShiftController::class, 'store'])
        ->name('admin.cabinet.shifts.store');

    Route::patch('cabinets/{cabinet}/shifts/{shift}', [CabinetShiftController::class, 'update'])
        ->name('admin.cabinet.shifts.update');

    Route::delete('cabinets/{cabinet}/shifts/{shift}', [CabinetShiftController::class, 'destroy'])
        ->name('admin.cabinet.shifts.delete');
});

Route::middleware(['auth'])->prefix('debug/onec')->group(function () {
    Route::get('{branch}/slots', function (Branch $branch) {
        abort_unless(auth()->user()?->hasRole('super_admin'), 403);

        $slots = OnecSlot::query()
            ->where('branch_id', $branch->id)
            ->orderByDesc('start_at')
            ->limit(200)
            ->get();

        return response()->json($slots);
    });
});
