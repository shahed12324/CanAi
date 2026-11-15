<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\EnsureActiveWorkspace;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:api-auth');
Route::post('auth/pair', [AuthController::class, 'pair'])->middleware('throttle:api-auth');

Route::middleware(['auth:sanctum', EnsureActiveWorkspace::class, 'throttle:60,1'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::get('auth/tokens', [AuthController::class, 'tokens']);
    Route::delete('auth/tokens/{id}', [AuthController::class, 'revokeToken'])->whereNumber('id');

    // Needs the X-Tenant-Id header; ResolveTenant verifies membership first.
    Route::middleware(ResolveTenant::class)->get('workspace', function () {
        $tenant = DB::table('tenants')->where('id', app(App\Tenancy\TenantContext::class)->idOrFail())
            ->first(['id', 'name', 'type', 'currency', 'timezone', 'locale']);

        return response()->json(['data' => $tenant]);
    });
});
