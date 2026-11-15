<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Messaging\ChannelSender;

final class FakeSender implements ChannelSender
{
    /** @var list<array{chat: string, text: string}> */
    public array $sent = [];

    public array $toasts = [];

    public int $typing = 0;

    public ?string $fileBytes = null;

    public bool $throwOnSend = false;

    public function sendText(string $accountId, string $chatId, string $text): void
    {
        if ($this->throwOnSend) {
            throw new \RuntimeException('telegram down');
        }
        $this->sent[] = ['chat' => $chatId, 'text' => $text];
    }

    public function typing(string $accountId, string $chatId): void
    {
        $this->typing++;
    }

    public function answerCallback(string $accountId, string $callbackId, string $text): void
    {
        $this->toasts[] = $text;
    }

    public function download(string $accountId, string $fileId, int $maxBytes): ?string
    {
        return $this->fileBytes;
    }
}
