<?php

declare(strict_types=1);

namespace App\Providers;

use App\Productivity\PgTaskRepo;
use App\Productivity\TaskRepo;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class ProductivityServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TaskRepo::class, PgTaskRepo::class);
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('tasks:remind')->everyMinute()->withoutOverlapping(5);
        });
    }
}
