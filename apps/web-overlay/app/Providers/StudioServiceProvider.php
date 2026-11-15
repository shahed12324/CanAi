<?php

declare(strict_types=1);

namespace App\Providers;

use App\Studio\CatalogImagePicker;
use App\Studio\DesignRepo;
use App\Studio\GatewayStudioEngine;
use App\Studio\ImagePicker;
use App\Studio\ImagePricing;
use App\Studio\PgDesignRepo;
use App\Studio\StudioEngine;
use Illuminate\Support\ServiceProvider;

final class StudioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StudioEngine::class, GatewayStudioEngine::class);
        $this->app->bind(ImagePicker::class, CatalogImagePicker::class);
        $this->app->bind(DesignRepo::class, PgDesignRepo::class);
        $this->app->bind(ImagePricing::class, fn () => ImagePricing::fromConfig());
    }
}
