<?php

declare(strict_types=1);

namespace App\Messaging;

use Illuminate\Support\Facades\DB;

/** One ChannelSender for every channel: looks up which channel the account belongs to and hands over to its client. */
final class ChannelRouter implements ChannelSender
{
    /** @var array<string, string> */
    private array $kinds = [];

    public function __construct(private readonly TelegramClient $telegram, private readonly WhatsAppClient $whatsapp) {}

    public function sendText(string $accountId, string $chatId, string $text): void
    {
        $this->for($accountId)->sendText($accountId, $chatId, $text);
    }

    public function typing(string $accountId, string $chatId): void
    {
        $this->for($accountId)->typing($accountId, $chatId);
    }

    public function answerCallback(string $accountId, string $callbackId, string $text): void
    {
        $this->for($accountId)->answerCallback($accountId, $callbackId, $text);
    }

    public function download(string $accountId, string $fileId, int $maxBytes): ?string
    {
        return $this->for($accountId)->download($accountId, $fileId, $maxBytes);
    }

    private function for(string $accountId): ChannelSender
    {
        $kind = $this->kinds[$accountId] ??= (string) DB::table('channel_accounts')->where('id', $accountId)->value('channel');

        return $kind === 'whatsapp' ? $this->whatsapp : $this->telegram;
    }
}
