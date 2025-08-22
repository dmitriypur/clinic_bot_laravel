<?php

use App\Http\Controllers\Bot\BotController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Telegram Bot webhook
Route::match(['get', 'post'], '/botman', [BotController::class, 'handle']);
