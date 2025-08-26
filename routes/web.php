<?php

use App\Http\Controllers\Bot\BotController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Booking');
});

// Telegram Bot webhook
Route::match(['get', 'post'], '/botman', [BotController::class, 'handle']);
