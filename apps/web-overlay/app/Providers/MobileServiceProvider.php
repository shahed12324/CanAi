<?php

declare(strict_types=1);

namespace App\Providers;

use App\Mobile\ApprovalAlert;
use App\Mobile\ExpoPush;
use App\Mobile\MobileAdminRepo;
use App\Mobile\MobileRepo;
use App\Mobile\NullPush;
use App\Mobile\PgMobileAdminRepo;
use App\Mobile\PgMobileRepo;
use App\Mobile\PushDispatcher;
use App\Mobile\PushSender;
use App\Models\User;
use App\Outreach\HttpJson;
use App\Outreach\LaravelHttpJson;
use App\Tenancy\TenantRoles;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class MobileServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MobileRepo::class, PgMobileRepo::class);
        $this->app->bind(MobileAdminRepo::class, PgMobileAdminRepo::class);
        $this->app->singletonIf(HttpJson::class, LaravelHttpJson::class);
        $this->app->bind(PushSender::class, fn ($app) => config('mobile.push') === 'expo'
            ? new ExpoPush($app->make(HttpJson::class), config('mobile.expo_access_token'))
            : new NullPush);
        $this->app->bind(PushDispatcher::class, fn ($app) => new PushDispatcher(
            $app->make(MobileAdminRepo::class), $app->make(PushSender::class),
            fn (string $tenant, string $user): bool => (bool) TenantRoles::inTeam($tenant, fn () => User::find($user)?->can('outreach.approve')),
        ));
        $this->app->bind(ApprovalAlert::class, fn ($app) => new ApprovalAlert($app->make(MobileAdminRepo::class), $app->make(PushDispatcher::class)));
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/v1/mobile')->group(base_path('routes/api_mobile.php'));

        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('mobile:push')->everyMinute()->withoutOverlapping(5);
        });
    }
}
