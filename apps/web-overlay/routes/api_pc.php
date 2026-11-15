<?php

use App\Http\Controllers\Api\Pc\AgentController;
use App\Http\Controllers\Api\Pc\DesktopController;
use App\Http\Middleware\PcAgentAuth;
use Illuminate\Support\Facades\Route;

// Prefix api/pc/v1. The desktop agent pairs with a one-time code, then uses its device token (`Authorization: Bearer <64 hex>`).
Route::middleware('throttle:120,1')->group(function () {
    Route::post('pair', [AgentController::class, 'pair'])->middleware('throttle:10,1');          // unauthenticated: strict limit against code guessing
    Route::middleware(PcAgentAuth::class)->group(function () {
        Route::get('actions/next', [AgentController::class, 'next']);
        Route::post('actions/{id}/report', [AgentController::class, 'report']);
        Route::get('emergency', [AgentController::class, 'emergency']);
        Route::get('plans/next', [AgentController::class, 'planNext']);
        Route::post('plans/{id}/report', [AgentController::class, 'planReport']);
        Route::post('metrics', [AgentController::class, 'metrics']);
        Route::get('audit/head', [AgentController::class, 'auditHead']);
        Route::post('audit/sync', [AgentController::class, 'auditSync'])->middleware('throttle:30,1');
        Route::post('rotate', [AgentController::class, 'rotate'])->middleware('throttle:5,1');
        // CAN AI Desktop window (docs/DESKTOP_API.md): same device token, runs as the device owner. No route here approves anything.
        Route::prefix('desktop')->group(function () {
            Route::get('bootstrap', [DesktopController::class, 'bootstrap']);
            Route::get('tools', [DesktopController::class, 'tools']);
            Route::get('approvals', [DesktopController::class, 'approvals']);
            Route::get('chat/conversations', [DesktopController::class, 'conversations']);
            Route::get('chat/conversations/{id}', [DesktopController::class, 'messages']);
            Route::post('chat', [DesktopController::class, 'chat'])->middleware('throttle:30,1');
            Route::post('chat/stream', [DesktopController::class, 'chatStream'])->middleware('throttle:30,1');
            Route::get('agents', [DesktopController::class, 'agents']);
            Route::post('agents/{id}/run', [DesktopController::class, 'runAgent'])->middleware('throttle:20,1');
            Route::get('workflows', [DesktopController::class, 'workflows']);
            Route::get('workflows/runs', [DesktopController::class, 'runs']);
            Route::post('workflows/{id}/run', [DesktopController::class, 'runWorkflow'])->middleware('throttle:20,1');
            Route::post('workflows/runs/{id}/cancel', [DesktopController::class, 'cancelRun']);
            Route::get('memory', [DesktopController::class, 'memory']);
            Route::post('memory', [DesktopController::class, 'addMemory']);
            Route::post('memory/{id}/delete', [DesktopController::class, 'deleteMemory']);
            Route::post('plan', [DesktopController::class, 'plan'])->middleware('throttle:10,1');
            Route::post('voice/listen', [DesktopController::class, 'listen'])->middleware('throttle:30,1');
            Route::post('voice/speak', [DesktopController::class, 'speak'])->middleware('throttle:30,1');
        });
    });
});
