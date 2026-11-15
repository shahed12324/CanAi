<?php

declare(strict_types=1);

namespace App\Providers;

use App\Ai\CredentialVault;
use App\Inbox\Channels\ChannelAdapter;
use App\Inbox\Channels\ChannelDispatcher;
use App\Inbox\Channels\ChannelSecrets;
use App\Inbox\Channels\MetaAdapter;
use App\Inbox\Channels\TelegramBotAdapter;
use App\Inbox\Agent\AgentRepo;
use App\Inbox\Agent\PgAgentRepo;
use App\Inbox\Channels\WebhookService;
use App\Inbox\InboxRepo;
use App\Inbox\PgInboxRepo;
use App\Inbox\WidgetService;
use App\Outreach\HttpJson;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class InboxServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InboxRepo::class, PgInboxRepo::class);
        $this->app->bind(WidgetService::class, fn ($app) => new WidgetService(
            $app->make(InboxRepo::class),
            fn (string $tenantId, Closure $f) => $app->make(TenantContext::class)->run($tenantId, $f),
            $this->agentHook($app),
        ));
        $this->app->bind(ChannelSecrets::class, fn ($app) => new ChannelSecrets($app->make(CredentialVault::class), $app->make(InboxRepo::class)));
        $this->app->bind(WebhookService::class, fn ($app) => new WebhookService(
            $app->make(InboxRepo::class),
            fn (string $channelId) => $app->make(ChannelSecrets::class)->open($channelId),
            fn (string $tenantId, Closure $f) => $app->make(TenantContext::class)->run($tenantId, $f),
            $this->agentHook($app),
        ));
        $this->app->bind(AgentRepo::class, PgAgentRepo::class);
        $this->app->bind(\App\Inbox\Agent\Orders\OrderDraftRepo::class, \App\Inbox\Agent\Orders\PgOrderDraftRepo::class);
        $this->app->bind(ChannelDispatcher::class, fn ($app) => new ChannelDispatcher(
            $app->make(InboxRepo::class),
            fn (string $kind): ChannelAdapter => $kind === 'telegram' ? new TelegramBotAdapter($app->make(HttpJson::class)) : new MetaAdapter($app->make(HttpJson::class), $kind, (string) config('inbox.graph_version', 'v21.0')),
            fn (string $channelId) => $app->make(ChannelSecrets::class)->open($channelId),
        ));
    }

    /** Called inside the workspace right after a customer message is stored: queue the AI only when the channel has the agent switched on. */
    private function agentHook($app): Closure
    {
        return function (string $tenantId, string $conversationId, int $seq) use ($app): void {
            $c = $app->make(InboxRepo::class)->conversation($conversationId);
            if ($c !== null && ($c['agent_mode'] ?? 'off') !== 'off' && $c['mode'] === 'bot') {
                \App\Jobs\RunSupportAgent::dispatch($tenantId, $conversationId, $seq);
            }
        };
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('webhooks/inbox')->group(base_path('routes/inbox_hooks.php'));
        $this->app->booted(function () {
            $this->app->make(Schedule::class)->command('inbox:dispatch')->everyMinute()->withoutOverlapping(5);
        });
        // Public, stateless (no cookies, no CSRF): visitors prove themselves with the channel key + origin + their own secret token.
        Route::middleware('api')->prefix('widget/v1')->group(base_path('routes/widget.php'));
    }
}
