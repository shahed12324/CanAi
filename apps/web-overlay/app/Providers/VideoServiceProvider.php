<?php

declare(strict_types=1);

namespace App\Providers;

use App\Assist\AssistEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditLedger;
use App\Video\GatewayVideoEngine;
use App\Video\LocalVideoFiles;
use App\Video\Narrator;
use App\Video\PgVideoRepo;
use App\Video\VideoEngine;
use App\Video\VideoFiles;
use App\Video\VideoPricing;
use App\Video\VideoRepo;
use App\Video\VideoScriptService;
use App\Video\VoiceNarrator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class VideoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VideoEngine::class, GatewayVideoEngine::class);
        $this->app->bind(VideoRepo::class, PgVideoRepo::class);
        $this->app->bind(VideoFiles::class, LocalVideoFiles::class);
        $this->app->bind(Narrator::class, VoiceNarrator::class);
        $this->app->bind(VideoScriptService::class, fn ($app) => new VideoScriptService($app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig()));
        $this->app->bind(VideoPricing::class, fn () => VideoPricing::fromConfig());
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('video:work')->everyMinute()->withoutOverlapping(15);
        });
    }
}
