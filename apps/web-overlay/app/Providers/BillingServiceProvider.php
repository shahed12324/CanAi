<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\BillingRepo;
use App\Billing\GatewayFactory;
use App\Billing\GatewayRegistry;
use App\Billing\PaymentService;
use App\Billing\PgBillingRepo;
use App\Http\Controllers\BillingCallbackController;
use App\Outreach\HttpJson;
use App\Outreach\LaravelHttpJson;
use App\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(BillingRepo::class, PgBillingRepo::class);
        $this->app->singletonIf(HttpJson::class, LaravelHttpJson::class);
        $this->app->singleton(GatewayRegistry::class, fn ($app) => GatewayFactory::make(
            (array) config('billing'), $app->make(HttpJson::class), (string) config('app.key'),
            fn (string $orderId) => route('billing.demo', ['order' => $orderId]),
        ));
        $this->app->bind(PaymentService::class, fn ($app) => new PaymentService(
            $app->make(BillingRepo::class), $app->make(GatewayRegistry::class),
            fn (string $tenant, \Closure $cb) => $app->make(TenantContext::class)->run($tenant, $cb),
        ));
    }

    public function boot(): void
    {
        // Gateways call these without a login or session, and may POST cross-site: 'api' group (no session, no CSRF).
        // The credential is the gateway's own proof, which PaymentService re-checks with the gateway before anything is applied.
        Route::middleware(['api', 'throttle:60,1'])->group(function () {
            Route::match(['get', 'post'], 'billing/return/{gateway}', [BillingCallbackController::class, 'customerReturn'])->where('gateway', '[a-z]{3,16}')->name('billing.return');
            Route::post('billing/ipn/{gateway}', [BillingCallbackController::class, 'ipn'])->where('gateway', '[a-z]{3,16}')->name('billing.ipn');
        });

        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('billing:run')->everyTenMinutes()->withoutOverlapping(10);
        });
    }
}
