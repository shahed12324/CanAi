<?php

use App\Http\Controllers\InboxWebhookController;
use Illuminate\Support\Facades\Route;

// {key}: the channel's 32-hex public key. The platform proves itself by signature (POST) or verify token (GET handshake).
Route::get('{key}', [InboxWebhookController::class, 'handshake'])->middleware('throttle:60,1');
Route::post('{key}', [InboxWebhookController::class, 'receive'])->middleware('throttle:600,1');
