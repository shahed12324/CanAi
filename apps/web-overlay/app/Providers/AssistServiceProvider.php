<?php

declare(strict_types=1);

namespace App\Providers;

use App\Assist\AssistEngine;
use App\Assist\AssistService;
use App\Assist\GatewayAssistEngine;
use App\Assist\TranslateService;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditLedger;
use Illuminate\Support\ServiceProvider;

final class AssistServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AssistEngine::class, GatewayAssistEngine::class);
        $this->app->bind(AssistService::class, fn ($app) => new AssistService($app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig()));
        $this->app->bind(TranslateService::class, fn ($app) => new TranslateService($app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig()));
    }
}
