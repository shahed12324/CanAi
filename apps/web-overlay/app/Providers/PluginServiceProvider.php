<?php

declare(strict_types=1);

namespace App\Providers;

use App\Plugins\PgPluginRepo;
use App\Plugins\PluginRepo;
use Illuminate\Support\ServiceProvider;

final class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PluginRepo::class, PgPluginRepo::class);
    }
}
