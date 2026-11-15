<?php

use App\Http\Controllers\Api\Public\DeveloperApiController;
use App\Http\Controllers\Api\Public\McpController;
use App\Http\Middleware\ApiKeyAuth;
use Illuminate\Support\Facades\Route;

// Prefix api/public/v1.  Auth: `Authorization: Bearer jk_live_…` (see app/Developer).  Each route names the one scope it needs.
Route::middleware('throttle:300,1')->group(function () {
    $c = DeveloperApiController::class;
    Route::get('openapi.json', [$c, 'openapi']);
    Route::get('me', [$c, 'me'])->middleware([ApiKeyAuth::class.':me', 'throttle:public-api']);
    Route::get('credits', [$c, 'credits'])->middleware([ApiKeyAuth::class.':credits:read', 'throttle:public-api']);
    Route::get('workflows', [$c, 'workflows'])->middleware([ApiKeyAuth::class.':workflows:read', 'throttle:public-api']);
    Route::get('runs/{id}', [$c, 'runStatus'])->middleware([ApiKeyAuth::class.':workflows:read', 'throttle:public-api']);
    Route::post('mcp', [McpController::class, 'handle'])->middleware([ApiKeyAuth::class.':*', 'throttle:public-api']);
    Route::get('mcp', [McpController::class, 'get']);
    Route::post('workflows/{id}/run', [$c, 'run'])->middleware([ApiKeyAuth::class.':workflows:run', 'throttle:public-api']);
});
