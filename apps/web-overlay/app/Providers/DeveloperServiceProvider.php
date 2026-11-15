<?php

declare(strict_types=1);

namespace App\Providers;

use App\Developer\ApiKeyRepo;
use App\Developer\CurlWebhookSender;
use App\Developer\PgApiKeyRepo;
use App\Developer\PgWebhookRepo;
use App\Developer\WebhookRepo;
use App\Developer\WebhookSender;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class DeveloperServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ApiKeyRepo::class, PgApiKeyRepo::class);
        $this->app->bind(WebhookRepo::class, PgWebhookRepo::class);
        $this->app->bind(WebhookSender::class, CurlWebhookSender::class);
    }

    public function boot(): void
    {
        // One bucket per key (not per IP): a developer's own server must not be throttled with strangers behind the same address.
        RateLimiter::for('public-api', fn (Request $r) => Limit::perMinute(60)->by((string) ($r->attributes->get('api_key_id') ?? $r->ip())));
        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('webhooks:work')->everyMinute()->withoutOverlapping(5);
        });
        Route::middleware('api')->prefix('api/public/v1')->group(base_path('routes/api_public.php'));
    }
}
