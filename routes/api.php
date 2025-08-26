<?php

use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\CityController;
use App\Http\Controllers\Api\ClinicController;
use App\Http\Controllers\Api\DoctorController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// API v1 routes (original Flask-like structure)
//Route::prefix('v1')->group(function () {
//    // Cities API - GET /api/v1/cities/
//    Route::get('cities/', [CityController::class, 'index']);
//
//    // Applications API - POST /api/v1/applications/create/
//    Route::post('applications/create/', [ApplicationController::class, 'store']);
//
//    // Webhook API - all CRUD operations
//    Route::get('webhook/', [WebhookController::class, 'index']);
//    Route::post('webhook/', [WebhookController::class, 'store']);
//    Route::get('webhook/{webhook}', [WebhookController::class, 'show']);
//    Route::put('webhook/{webhook}', [WebhookController::class, 'update']);
//    Route::delete('webhook/{webhook}', [WebhookController::class, 'destroy']);
//});

// Additional RESTful API routes for full CRUD
Route::prefix('v1')->group(function () {
    Route::apiResource('cities', CityController::class);
    Route::get('cities/{city}/clinics',[ClinicController::class,'byCity']);
    Route::apiResource('applications', ApplicationController::class);
    Route::apiResource('doctors', DoctorController::class);
    Route::get('/clinics/{clinic}/doctors',[DoctorController::class,'byClinic']);
    Route::get('/cities/{city}/doctors',[DoctorController::class,'byCity']);
    Route::apiResource('clinics', ClinicController::class);

    // Protected webhook routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::apiResource('webhooks', WebhookController::class);
    });
});
