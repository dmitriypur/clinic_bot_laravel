<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\IntegrationWebhookController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Additional RESTful API routes for full CRUD
Route::prefix('v1')->group(function () {
    Route::apiResource('cities', CityController::class);
    Route::get('cities/{city}/clinics', [ClinicController::class, 'byCity']);
    Route::apiResource('applications', ApplicationController::class);
    Route::post('applications/check-slot', [ApplicationController::class, 'checkSlot'])
        ->name('applications.checkSlot');
    Route::apiResource('doctors', DoctorController::class);
    Route::get('/doctors/{doctor}/slots', [DoctorController::class, 'slots']);
    Route::get('/booking/calendar-availability', [DoctorController::class, 'calendarAvailability']);
    Route::get('/clinics/{clinic}/branches', [ClinicController::class, 'branches']);
    Route::get('/clinics/{clinic}/doctors', [DoctorController::class, 'byClinic']);
    Route::get('/cities/{city}/doctors', [DoctorController::class, 'byCity']);
    Route::apiResource('clinics', ClinicController::class);

    // Protected webhook routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('webhooks', WebhookController::class);
    });

    Route::post('integrations/{clinic}/bookings/webhook', [IntegrationWebhookController::class, 'handleBookings'])
        ->name('integrations.bookings.webhook');
    Route::post('integrations/{clinic}/schedule', [IntegrationWebhookController::class, 'handleSchedule'])
        ->name('integrations.schedule.webhook');
});
