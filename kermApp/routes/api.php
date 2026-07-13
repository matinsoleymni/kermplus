<?php

use App\Http\Controllers\Api\App\DeliveryController as AppDeliveryController;
use App\Http\Controllers\Api\App\DeviceController as AppDeviceController;
use App\Http\Controllers\Api\Bot\DeviceController as BotDeviceController;
use App\Http\Controllers\Api\Bot\EventController;
use App\Http\Controllers\Api\Bot\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Bot API
|--------------------------------------------------------------------------
| Consumed by the Telegram bot. Owner registration is gated by the shared
| bot secret; every other endpoint is scoped to the owner's api_token.
*/
Route::prefix('bot')->group(function () {
    Route::post('users', [UserController::class, 'store'])->middleware('auth.bot');

    Route::middleware('auth.owner')->group(function () {
        Route::get('devices', [BotDeviceController::class, 'index']);
        Route::get('events', [EventController::class, 'index']);
        Route::post('events', [EventController::class, 'store']);
        Route::get('events/{event}', [EventController::class, 'show']);
    });
});

/*
|--------------------------------------------------------------------------
| App API
|--------------------------------------------------------------------------
| Consumed by the installed Android app. The owner is resolved from the
| app_key embedded in that owner's dedicated build.
*/
Route::prefix('app')->middleware('auth.app')->group(function () {
    Route::post('devices', [AppDeviceController::class, 'register']);
    Route::post('devices/token', [AppDeviceController::class, 'updateToken']);
    Route::post('deliveries/{delivery}/ack', [AppDeliveryController::class, 'acknowledge']);
});
