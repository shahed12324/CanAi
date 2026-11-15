<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\CredentialVault;
use App\Calendar\CalendarAccounts;
use App\Calendar\CalendarService;
use App\Calendar\GoogleCalendarApi;
use App\Calendar\GoogleCalendarOAuth;
use App\Calendar\GraphCalendarApi;
use App\Calendar\MicrosoftCalendarOAuth;
use App\Calendar\HttpIcsFetcher;
use App\Calendar\IcsFetcher;
use App\Links\ChatHooks;
use App\Links\HttpWebhookPoster;
use App\Links\LinkRepo;
use App\Links\PgLinkRepo;
use App\Links\Secrets;
use App\Links\VaultSecrets;
use App\Links\WebhookPoster;
use App\Outreach\HttpJson;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;

final class ConnectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LinkRepo::class, PgLinkRepo::class);
        $this->app->bind(Secrets::class, fn ($app) => new VaultSecrets($app->make(CredentialVault::class)));
        $this->app->bind(IcsFetcher::class, HttpIcsFetcher::class);
        $this->app->bind(WebhookPoster::class, HttpWebhookPoster::class);
        $this->app->bind(ChatHooks::class, fn ($app) => new ChatHooks($app->make(LinkRepo::class), $app->make(Secrets::class), $app->make(WebhookPoster::class)));
        // The calendar OAuth uses the same Google / Microsoft app keys as the e-mail connection (config/outreach.php), asked for calendar permission only.
        $this->app->singleton('calendar.oauth', fn ($app) => [
            'google' => new GoogleCalendarOAuth($app->make(HttpJson::class), config('outreach.google.client_id'), config('outreach.google.client_secret')),
            'microsoft' => new MicrosoftCalendarOAuth($app->make(HttpJson::class), config('outreach.microsoft.client_id'), config('outreach.microsoft.client_secret'), (string) config('outreach.microsoft.tenant', 'common')),
        ]);
        $this->app->bind(CalendarAccounts::class, fn ($app) => new CalendarAccounts(
            $app->make(LinkRepo::class), $app->make(Secrets::class), $app->make('calendar.oauth'),
            ['google' => new GoogleCalendarApi($app->make(HttpJson::class)), 'microsoft' => new GraphCalendarApi($app->make(HttpJson::class))],
            fn () => new \DateTimeImmutable('now'),
        ));
        $this->app->bind(CalendarService::class, fn ($app) => new CalendarService(
            $app->make(LinkRepo::class), $app->make(Secrets::class), $app->make(IcsFetcher::class),
            fn (string $key, int $ttl, \Closure $make): string => (string) Cache::remember($key, $ttl, $make),
            $app->make(CalendarAccounts::class),
        ));
    }
}
