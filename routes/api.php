<?php

use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\RemoteCommandController;
use App\Http\Controllers\Api\V1\SyncEventController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Device API (/api/v1)
|--------------------------------------------------------------------------
|
| Only registered devices authenticate here, with a revocable Sanctum bearer
| token. Administrators use the session-based web interface instead.
|
*/

Route::prefix('v1')
    ->middleware(['request.limit', 'throttle:device-ip', 'auth:sanctum', 'device', 'throttle:device-api'])
    ->group(function (): void {
        Route::get('device/me', [DeviceController::class, 'me'])->name('api.v1.device.me');
        Route::post('device/heartbeat', [DeviceController::class, 'heartbeat'])->name('api.v1.device.heartbeat');

        Route::post('sync/events', [SyncEventController::class, 'store'])->name('api.v1.sync.events');

        Route::get('device/commands', [RemoteCommandController::class, 'index'])->name('api.v1.device.commands');
        Route::post('device/commands/{command}/ack', [RemoteCommandController::class, 'acknowledge'])
            ->whereUuid('command')
            ->name('api.v1.device.commands.ack');
    });
