<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\CredentialVault;
use App\Ai\DbProviderStore;
use App\Ai\ProviderGateway;
use App\Ai\ProviderStore;
use App\Services\AiEngine\AiEngineClient;
use App\Services\AiEngine\RequestSigner;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

final class AiEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RequestSigner::class, fn () => new RequestSigner(
            (string) config('ai_engine.hmac_secret'),
            (string) config('ai_engine.jwt_secret'),
        ));

        $this->app->singleton(AiEngineClient::class, fn ($app) => new AiEngineClient(
            $app->make(Factory::class),
            $app->make(RequestSigner::class),
            (string) config('ai_engine.url'),
            (int) config('ai_engine.timeout'),
        ));

        $this->app->singleton(CredentialVault::class, fn () => new CredentialVault(
            (array) config('ai_engine.vault.keys'),
            (int) config('ai_engine.vault.current'),
        ));
        $this->app->bind(ProviderStore::class, DbProviderStore::class);
        $this->app->singleton(ProviderGateway::class);
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(\Illuminate\Console\Scheduling\Schedule::class)
                ->command('ai:health-check')->everyFiveMinutes()->withoutOverlapping();
        });
    }
}
