<?php

declare(strict_types=1);

namespace Jarvis\Connector;

use Illuminate\Support\ServiceProvider;
use Jarvis\Connector\Console\CheckCommand;
use Jarvis\Connector\Console\SecretCommand;

final class ConnectorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/jarvis-connector.php', 'jarvis-connector');
        $this->app->bind(ProductStore::class, function ($app) {
            $custom = config('jarvis-connector.store');
            if (is_string($custom) && $custom !== '') {
                return $app->make($custom);
            }

            return new DatabaseProductStore(new LaravelDb(config('jarvis-connector.connection')), (array) config('jarvis-connector.database'));
        });
        $this->app->bind(Handler::class, fn ($app) => new Handler(
            $app->make(ProductStore::class), (string) config('jarvis-connector.secret', ''), new CacheNonceStore, fn () => time(),
            (string) config('jarvis-connector.name', 'Store'), (string) config('jarvis-connector.database.currency', 'BDT'),
        ));
    }

    public function boot(): void
    {
        $this->publishes([__DIR__.'/../config/jarvis-connector.php' => config_path('jarvis-connector.php')], 'jarvis-connector-config');
        $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        if ($this->app->runningInConsole()) {
            $this->commands([SecretCommand::class, CheckCommand::class]);
        }
    }
}
