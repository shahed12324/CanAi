<?php

declare(strict_types=1);

namespace App\Providers;

use App\Chat\CreditPricing;
use App\Credits\CreditLedger;
use App\Vision\CatalogReadPicker;
use App\Vision\GatewayVisionEngine;
use App\Vision\VisionEngine;
use App\Vision\VisionReader;
use Illuminate\Support\ServiceProvider;

final class VisionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VisionEngine::class, GatewayVisionEngine::class);
        $this->app->bind(VisionReader::class, fn ($app) => new VisionReader(
            $app->make(VisionEngine::class), $app->make(CatalogReadPicker::class), $app->make(CreditLedger::class), CreditPricing::fromConfig(),
        ));
    }
}
