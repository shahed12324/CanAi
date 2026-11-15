<?php

declare(strict_types=1);

namespace App\Providers;

use App\Assist\AssistEngine;
use App\Chat\CreditPricing;
use App\Chat\ModelPicker;
use App\Credits\CreditLedger;
use App\Voice\CatalogVoicePicker;
use App\Voice\GatewayVoiceEngine;
use App\Voice\VoiceEngine;
use App\Voice\VoicePicker;
use App\Voice\VoiceActions;
use App\Voice\VoicePricing;
use App\Voice\WorkflowVoiceActions;
use App\Voice\VoiceService;
use Illuminate\Support\ServiceProvider;

final class VoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(VoiceEngine::class, GatewayVoiceEngine::class);
        $this->app->bind(VoicePicker::class, CatalogVoicePicker::class);
        $this->app->bind(VoiceActions::class, fn ($app) => new WorkflowVoiceActions($app->make(\App\Workflow\WorkflowRepo::class), $app->make(\App\Workflow\WorkflowService::class)));
        $this->app->bind(VoiceService::class, fn ($app) => new VoiceService(
            $app->make(VoiceEngine::class), $app->make(VoicePicker::class), $app->make(ModelPicker::class), $app->make(AssistEngine::class),
            $app->make(CreditLedger::class), VoicePricing::fromConfig(), CreditPricing::fromConfig(), $app->make(VoiceActions::class),
        ));
    }
}
