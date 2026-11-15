<?php

declare(strict_types=1);

namespace App\Providers;

use App\Crm\CrmService;
use App\Crm\CrmStore;
use App\Crm\PgCrmStore;
use App\Leads\HttpLeadEngine;
use App\Leads\LeadEngine;
use App\Leads\LeadStore;
use App\Leads\PgLeadStore;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

final class LeadServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LeadStore::class, PgLeadStore::class);
        $this->app->bind(LeadEngine::class, HttpLeadEngine::class);
        $this->app->bind(CrmStore::class, PgCrmStore::class);
        $this->app->bind(CrmService::class, fn ($app) => new CrmService($app->make(CrmStore::class)));
    }

    public function boot(): void
    {
        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)->command('leads:requeue-stale')->everyTenMinutes()->withoutOverlapping();
        });
    }
}
