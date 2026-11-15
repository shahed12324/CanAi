<?php

use App\Http\Controllers\WidgetController;
use Illuminate\Support\Facades\Route;

// (Platform webhooks live in routes/inbox_hooks.php.)
// {key} is the channel's 32-hex public key. OPTIONS is answered for CORS preflight.
Route::options('{key}/{any?}', [WidgetController::class, 'preflight'])->where('any', '.*');
Route::post('{key}/start', [WidgetController::class, 'start'])->middleware('throttle:30,1');
Route::post('{key}/messages', [WidgetController::class, 'post'])->middleware('throttle:40,1');
Route::get('{key}/messages', [WidgetController::class, 'poll'])->middleware('throttle:90,1');
