<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

final class WebServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1')->group(base_path('routes/api_v1.php'));

        // The app layout needs the user's workspaces for the switcher.
        View::composer('components.layouts.app', function ($view): void {
            $user = request()->user();
            $view->with('navWorkspaces', $user === null ? collect() : DB::table('tenant_users as tu')
                ->join('tenants as t', 't.id', '=', 'tu.tenant_id')
                ->where('tu.user_id', $user->getKey())->where('tu.status', 'active')
                ->where('t.status', 'active')->whereNull('t.deleted_at')
                ->orderBy('t.name')->get(['t.id', 't.name']));
            $view->with('navBrand', app(\App\Brand\BrandService::class)->forLayout(app(\App\Tenancy\TenantContext::class)->id()));
            $view->with('navCurrent', request()->hasSession() ? request()->session()->get('tenant_id') : null);
        });
    }
}
