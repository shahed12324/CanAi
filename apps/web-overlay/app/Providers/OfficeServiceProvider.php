<?php

declare(strict_types=1);

namespace App\Providers;

use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditLedger;
use App\Office\GatewayOfficeEngine;
use App\Office\OfficeEngine;
use App\Office\OfficeRepo;
use App\Office\OfficeService;
use App\Office\PgOfficeRepo;
use Illuminate\Support\ServiceProvider;

final class OfficeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(OfficeRepo::class, PgOfficeRepo::class);
        $this->app->bind(OfficeEngine::class, GatewayOfficeEngine::class);
        $this->app->bind(OfficeService::class, fn ($app) => new OfficeService(
            $app->make(ModelPicker::class), $app->make(OfficeEngine::class), $app->make(OfficeRepo::class), $app->make(CreditLedger::class), CreditPricing::fromConfig(),
        ));
    }
}
