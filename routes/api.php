<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\BookingIntegrationController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\DoctorBranchesAvailabilityController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\DoctorDateCalendarController;
use App\Http\Controllers\Api\IntegrationWebhookController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Middleware\RequireBookingIntegrationAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('integrations/v1')->middleware('throttle:120,1,booking-integrations')->group(function () {
    Route::get('catalog/search', [BookingIntegrationController::class, 'catalog'])
        ->middleware(RequireBookingIntegrationAccess::class.':catalog:read');
    Route::post('availability/search', [BookingIntegrationController::class, 'availability'])
        ->middleware(RequireBookingIntegrationAccess::class.':availability:read');
    Route::post('bookings', [BookingIntegrationController::class, 'book'])
        ->middleware(RequireBookingIntegrationAccess::class.':bookings:write');
    Route::get('operations/{id}', [BookingIntegrationController::class, 'operation'])
        ->whereUuid('id')->middleware(RequireBookingIntegrationAccess::class.':operations:read');
});

Route::prefix('v1')->group(function () {
    Route::apiResource('cities', CityController::class)->only(['index', 'show']);
    Route::get('cities/{city}/clinics', [ClinicController::class, 'byCity']);
    Route::post('applications', [ApplicationController::class, 'store'])->name('applications.store');
    Route::post('applications/check-slot', [ApplicationController::class, 'checkSlot'])
        ->name('applications.checkSlot');
    Route::apiResource('doctors', DoctorController::class)->only(['index']);
    Route::get('/doctors/{doctor}/branches-availability', DoctorBranchesAvailabilityController::class);
    Route::get('/doctors/{doctor}/slots', [DoctorController::class, 'slots']);
    Route::get('/booking/calendar-availability', [DoctorController::class, 'calendarAvailability']);
    Route::get('/cities/{city}/doctors-by-date', [DoctorController::class, 'availableByDate']);
    Route::get('/cities/{city}/doctors-by-date/calendar', DoctorDateCalendarController::class);
    Route::get('/clinics/{clinic}/branches', [ClinicController::class, 'branches']);
    Route::get('/clinics/{clinic}/doctors', [DoctorController::class, 'byClinic']);
    Route::get('/cities/{city}/doctors', [DoctorController::class, 'byCity']);
    Route::apiResource('clinics', ClinicController::class)->only(['index']);

    // Protected webhook routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('webhooks', WebhookController::class);
    });

    Route::post('integrations/{clinic}/bookings/webhook', [IntegrationWebhookController::class, 'handleBookings'])
        ->name('integrations.bookings.webhook');
    Route::post('integrations/{clinic}/schedule', [IntegrationWebhookController::class, 'handleSchedule'])
        ->name('integrations.schedule.webhook');
});
