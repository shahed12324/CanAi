<?php

declare(strict_types=1);

namespace App\Providers;

use App\Tenancy\TenantContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Sanctum 3.x loaded its own migration (we ship our own, with ULID keys); Sanctum 4+ never auto-loads it and has no such method.
        if (method_exists(Sanctum::class, 'ignoreMigrations')) {
            Sanctum::ignoreMigrations();
        }
        $this->app->singleton(TenantContext::class, fn ($app) => new TenantContext($app->make('db')->connection()));
    }

    public function boot(): void
    {
        $context = fn (): TenantContext => $this->app->make(TenantContext::class);

        // Every queued job remembers the tenant that dispatched it...
        Queue::createPayloadUsing(fn () => ['tenant_id' => $context()->id()]);

        // ...and the worker restores it for that job only, then always clears it.
        Queue::before(function (JobProcessing $event) use ($context): void {
            $tenantId = $event->job->payload()['tenant_id'] ?? null;
            is_string($tenantId) && TenantContext::isValidId($tenantId)
                ? $context()->set($tenantId)
                : $context()->clear();
        });
        Queue::after(fn (JobProcessed $e) => $context()->clear());
        Queue::failing(fn (JobFailed $e) => $context()->clear());
        Queue::exceptionOccurred(fn (JobExceptionOccurred $e) => $context()->clear());
        Queue::looping(fn (Looping $e) => $context()->clear());
    }
}
