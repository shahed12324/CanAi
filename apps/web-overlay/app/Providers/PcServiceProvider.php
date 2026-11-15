<?php

declare(strict_types=1);

namespace App\Providers;

use App\Pc\PcRepo;
use App\Companion\ModeRepo;
use App\Companion\PgModeRepo;
use App\Pc\PcPlanRepo;
use App\Pc\PgPcPlanRepo;
use App\Pc\PgPcRepo;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class PcServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PcRepo::class, PgPcRepo::class);
        $this->app->bind(PcPlanRepo::class, PgPcPlanRepo::class);
        $this->app->bind(\App\Pc\PcPlanner::class, fn ($app) => new \App\Pc\PcPlanner(
            $app->make(\App\Chat\ModelPicker::class), $app->make(\App\Pc\PlanEngine::class), $app->make(PcRepo::class), $app->make(PcPlanRepo::class), $app->make(\App\Credits\CreditLedger::class), \App\Chat\CreditPricing::fromConfig(), $app->make(\App\Mobile\ApprovalAlert::class),
        ));
        $this->app->bind(\App\Pc\PlanEngine::class, \App\Pc\GatewayPlanEngine::class);
        $this->app->bind(ModeRepo::class, PgModeRepo::class);
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api/pc/v1')->group(base_path('routes/api_pc.php'));
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)->command('pc:purge-metrics')->dailyAt('03:40')->withoutOverlapping();
        });
    }
}
