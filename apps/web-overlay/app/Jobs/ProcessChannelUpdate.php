<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Messaging\GatewayHandler;
use App\Messaging\InboundMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One Telegram update. The event was claimed by the webhook; GatewayHandler never throws and always records the outcome. */
final class ProcessChannelUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;          // a retry would only meet its own claim; Telegram's own retry reopens a crashed event after 2 min

    public int $timeout = 120;

    /** @param array<string, mixed> $update */
    public function __construct(public readonly string $accountId, public readonly array $update) {}

    public function handle(GatewayHandler $handler): void
    {
        $handler->handle($this->accountId, InboundMessage::fromArray($this->update));
    }
}
