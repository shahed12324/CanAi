<?php

declare(strict_types=1);

namespace App\Providers;

use App\Assist\AssistEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditLedger;
use App\Skills\PaidCompletion;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class AutomationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // CreditPricing needs its rates; any class that asks the container for it directly gets the configured one.
        $this->app->bind(CreditPricing::class, fn () => CreditPricing::fromConfig());
        $this->app->bind(PaidCompletion::class, fn ($app) => new PaidCompletion($app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig()));
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('jobs:run-due')->everyMinute()->withoutOverlapping(30);
        });
    }
}
