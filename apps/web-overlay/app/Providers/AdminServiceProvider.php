<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Middleware\AdminAccess;
use App\Http\Middleware\CheckMaintenance;
use App\Support\SystemSettings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class AdminServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A second connection that logs in as jarvis_admin_login (member of jarvis_admin).
        // Only admin-panel code uses it; the normal app never gets cross-tenant rights.
        $base = config('database.connections.pgsql', []);
        config(['database.connections.admin' => array_merge($base, [
            'username' => config('admin_db.username', 'jarvis_admin_login'),
            'password' => config('admin_db.password'),
        ])]);
    }

    public function boot(Router $router): void
    {
        Route::middleware(['web', 'auth', 'verified', 'password.confirm', AdminAccess::class, 'throttle:120,1'])
            ->prefix('admin')->name('admin.')
            ->group(base_path('routes/admin.php'));

        $router->pushMiddlewareToGroup('web', CheckMaintenance::class);
        $router->pushMiddlewareToGroup('api', CheckMaintenance::class);

        // Branding from system settings overrides the config defaults (cached for 30 s).
        config([
            'brand.name' => SystemSettings::get('branding.name', config('brand.name')),
            'brand.short_name' => SystemSettings::get('branding.short_name', config('brand.short_name')),
            'brand.support_email' => SystemSettings::get('branding.support_email', config('brand.support_email')),
        ]);
    }
}
