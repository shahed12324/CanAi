<?php

declare(strict_types=1);

namespace App\Providers;

use App\Credits\CreditService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class CreditServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CreditService::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);
            $schedule->command('credits:release-stale')->everyMinute()->withoutOverlapping();
            $schedule->command('credits:expire')->hourly()->withoutOverlapping();
            $schedule->command('credits:notify-low-balance')->everyTenMinutes()->withoutOverlapping();
            $schedule->command('credits:reconcile')->dailyAt('03:10')->withoutOverlapping();
        });
    }
}
