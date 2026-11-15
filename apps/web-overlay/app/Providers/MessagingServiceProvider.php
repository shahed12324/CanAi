<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\TelegramWebhookController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Messaging\ChannelRouter;
use App\Messaging\ApprovalDecider;
use App\Messaging\AttemptLimiter;
use App\Messaging\CacheAttemptLimiter;
use App\Messaging\ChannelSender;
use App\Messaging\ChannelStore;
use App\Messaging\ChatBridge;
use App\Messaging\GatewayHandler;
use App\Messaging\NullApprovalDecider;
use App\Messaging\PgChannelStore;
use App\Messaging\ServiceChatBridge;
use App\Messaging\ServiceVoiceInbound;
use App\Messaging\VoiceInbound;
use App\Messaging\TelegramApi;
use App\Messaging\TelegramClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class MessagingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TelegramApi::class, fn () => new TelegramApi((string) config('messaging.telegram_api')));
        $this->app->bind(ChannelStore::class, PgChannelStore::class);
        $this->app->bind(ChannelSender::class, ChannelRouter::class);
        $this->app->bind(ChatBridge::class, ServiceChatBridge::class);
        $this->app->bind(AttemptLimiter::class, CacheAttemptLimiter::class);
        $this->app->bindIf(ApprovalDecider::class, NullApprovalDecider::class);   // the approvals phase rebinds this
        $this->app->bind(GatewayHandler::class, fn ($app) => new GatewayHandler(
            $app->make(ChannelStore::class), $app->make(ChannelSender::class), $app->make(ChatBridge::class), $app->make(AttemptLimiter::class),
            $app->make(ApprovalDecider::class), (array) config('chat.attach_extensions'), (int) config('messaging.max_file_bytes'),
            (int) config('messaging.messages_per_minute'), (bool) config('messaging.show_cost'),
            $app->make(VoiceInbound::class),
        ));
        $this->app->bind(VoiceInbound::class, ServiceVoiceInbound::class);
    }

    public function boot(): void
    {
        // 'api' group: no session and no CSRF. Authentication is the secret-token header (see the controller).
        Route::middleware(['api', 'throttle:1200,1'])->post('webhooks/telegram/{account}', TelegramWebhookController::class)->name('telegram.webhook');
        Route::middleware(['api', 'throttle:1200,1'])->get('webhooks/whatsapp/{account}', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.verify');
        Route::middleware(['api', 'throttle:1200,1'])->post('webhooks/whatsapp/{account}', [WhatsAppWebhookController::class, 'receive'])->name('whatsapp.webhook');

        $this->app->booted(function (): void {
            $this->app->make(Schedule::class)->command('channels:purge-events')->dailyAt('03:40');
        });
    }
}
