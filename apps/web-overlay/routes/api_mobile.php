<?php

use App\Http\Controllers\Api\Mobile\ApprovalController;
use App\Http\Controllers\Api\Mobile\BootstrapController;
use App\Http\Controllers\Api\Mobile\ChatController;
use App\Http\Controllers\Api\Mobile\DeviceController;
use App\Http\Controllers\Api\Mobile\EmergencyController;
use App\Http\Controllers\Api\Mobile\NotificationController;
use App\Http\Controllers\Api\Mobile\OverviewController;
use App\Http\Controllers\Api\Mobile\PcActionController;
use App\Http\Middleware\EnsureActiveWorkspace;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\Route;

// Prefix api/v1/mobile. Login itself is POST api/v1/auth/login (Sanctum token, 30 days, device_name required).
Route::middleware(['auth:sanctum', EnsureActiveWorkspace::class, 'throttle:120,1'])->group(function () {
    Route::get('bootstrap', BootstrapController::class);

    Route::middleware(ResolveTenant::class)->group(function () {
        Route::post('device', [DeviceController::class, 'register']);
        Route::put('device/push', [DeviceController::class, 'push']);
        Route::delete('device', [DeviceController::class, 'revoke']);
        Route::post('pin', [DeviceController::class, 'setPin'])->middleware('throttle:6,1');
        Route::post('step-up', [DeviceController::class, 'stepUp'])->middleware('throttle:12,1');

        Route::get('dashboard', [OverviewController::class, 'dashboard']);
        Route::get('usage', [OverviewController::class, 'usage']);
        Route::get('automations', [OverviewController::class, 'automations']);
        Route::get('health', [OverviewController::class, 'health']);

        Route::get('approvals', [ApprovalController::class, 'index']);
        Route::post('approvals/{kind}/{ref}', [ApprovalController::class, 'decide'])->middleware('throttle:30,1');

        Route::get('pc/devices', [PcActionController::class, 'devices']);
        Route::post('pc/request', [PcActionController::class, 'request'])->middleware('throttle:20,1');
        Route::get('pc/actions', [PcActionController::class, 'index']);
        Route::post('pc/actions/{id}', [PcActionController::class, 'decide'])->middleware('throttle:30,1');
        Route::get('pc/plans', [PcActionController::class, 'plans']);
        Route::post('pc/plans/{id}/step', [PcActionController::class, 'planStep'])->middleware('throttle:30,1');

        Route::get('mode', [PcActionController::class, 'mode']);
        Route::post('mode', [PcActionController::class, 'setMode'])->middleware('throttle:10,1');

        Route::get('emergency', [EmergencyController::class, 'show']);
        Route::post('emergency', [EmergencyController::class, 'set'])->middleware('throttle:10,1');

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read', [NotificationController::class, 'read']);

        Route::get('chat/conversations', [ChatController::class, 'conversations']);
        Route::get('chat/conversations/{id}', [ChatController::class, 'messages']);
        Route::post('chat/send', [ChatController::class, 'send'])->middleware('throttle:20,1');
    });
});
