<?php

declare(strict_types=1);

namespace App\Providers;

use App\Catalog\AiWriter;
use App\Catalog\CatalogRepo;
use App\Catalog\CatalogService;
use App\Catalog\PgCatalogRepo;
use App\Chat\ChatEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditLedger;
use App\Ai\CredentialVault;
use App\Catalog\Stores\PgStoreRepo;
use App\Catalog\Stores\StoreConnector;
use App\Catalog\Stores\StoreFactory;
use App\Catalog\Stores\StoreRepo;
use App\Catalog\Stores\StoreSync;
use App\Outreach\HttpJson;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CatalogRepo::class, PgCatalogRepo::class);
        $this->app->bind(CatalogService::class, fn ($app) => new CatalogService($app->make(CatalogRepo::class)));
        $this->app->bind(AiWriter::class, fn ($app) => new AiWriter(
            $app->make(ModelPicker::class), $app->make(ChatEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig(),
        ));
        $this->app->bind(StoreRepo::class, PgStoreRepo::class);
        $this->app->bind(StoreFactory::class, fn ($app) => new StoreFactory(
            $app->make(HttpJson::class), $app->make(CredentialVault::class), $app->make(StoreRepo::class), (string) config('catalog.shopify_api_version', '2026-07'),
        ));
        $this->app->bind(StoreSync::class, fn ($app) => new StoreSync($app->make(CatalogRepo::class), $app->make(StoreRepo::class), $app->make(StoreFactory::class)));
        $this->app->bind(StoreConnector::class, fn ($app) => new StoreConnector($app->make(StoreRepo::class), $app->make(StoreFactory::class)));
    }

    public function boot(): void
    {
        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('catalog:sync')->everyFiveMinutes()->withoutOverlapping(20);
        });
    }
}
