<?php

declare(strict_types=1);

namespace App\Providers;

use App\Chat\ChatStore;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditLedger;
use App\Memory\GatewayMemoryEngine;
use App\Memory\MemoryContext;
use App\Memory\MemoryEngine;
use App\Memory\MemoryRepo;
use App\Memory\MemorySuggestService;
use App\Memory\PgMemoryRepo;
use App\Memory\RepoMemoryContext;
use Illuminate\Support\ServiceProvider;

final class MemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(MemoryRepo::class, PgMemoryRepo::class);
        $this->app->bind(MemoryContext::class, RepoMemoryContext::class);
        $this->app->bind(MemoryEngine::class, GatewayMemoryEngine::class);
        $this->app->bind(MemorySuggestService::class, fn ($app) => new MemorySuggestService(
            $app->make(ChatStore::class), $app->make(ModelPicker::class), $app->make(MemoryEngine::class), $app->make(MemoryRepo::class), $app->make(CreditLedger::class), CreditPricing::fromConfig(),
        ));
    }
}
