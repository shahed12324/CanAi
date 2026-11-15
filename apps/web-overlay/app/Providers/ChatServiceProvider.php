<?php

declare(strict_types=1);

namespace App\Providers;

use App\Chat\CatalogModelPicker;
use App\Chat\ChatEngine;
use App\Chat\ChatService;
use App\Chat\ChatStore;
use App\Chat\CreditPricing;
use App\Chat\GatewayChatEngine;
use App\Chat\ModelPicker;
use App\Chat\PgChatStore;
use App\Credits\CreditLedger;
use App\Credits\CreditService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CreditLedger::class, CreditService::class);
        $this->app->bind(ChatStore::class, PgChatStore::class);
        $this->app->bind(ChatEngine::class, GatewayChatEngine::class);
        $this->app->bind(ModelPicker::class, CatalogModelPicker::class);
        $this->app->bind(ChatService::class, fn ($app) => new ChatService(
            $app->make(ModelPicker::class), $app->make(ChatStore::class), $app->make(ChatEngine::class),
            $app->make(CreditLedger::class), CreditPricing::fromConfig(),
            (int) config('chat.max_output', 2048), (int) config('chat.max_input', 24000),
            $app->make(\App\Memory\MemoryContext::class), $app->make(\App\Knowledge\KnowledgeContext::class), $app->make(\App\Character\CharacterRepo::class),
            $app->make(\App\Companion\ModeRepo::class),
        ));
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)->command('chat:finalize-stale')->everyFiveMinutes()->withoutOverlapping();
        });
    }
}
