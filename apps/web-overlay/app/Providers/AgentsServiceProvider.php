<?php

declare(strict_types=1);

namespace App\Providers;

use App\Agents\AgentRepo;
use App\Agents\AgentService;
use App\Agents\PgAgentRepo;
use App\Assist\AssistEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditLedger;
use App\Workflow\ToolRegistry;
use Illuminate\Support\ServiceProvider;

final class AgentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AgentRepo::class, PgAgentRepo::class);
        $this->app->bind(AgentService::class, fn ($app) => new AgentService(
            $app->make(AgentRepo::class), $app->make(ToolRegistry::class), $app->make(ModelPicker::class), $app->make(AssistEngine::class), $app->make(CreditLedger::class), CreditPricing::fromConfig(),
        ));
    }
}
