<?php

use Illuminate\Support\Facades\Route;
use Jarvis\Connector\Http\ConnectorController;

Route::middleware(config('jarvis-connector.middleware', ['api']))
    ->any('jarvis-connector/v1/{path?}', ConnectorController::class)
    ->where('path', '.*')
    ->name('jarvis-connector');
