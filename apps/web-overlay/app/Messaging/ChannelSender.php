<?php

declare(strict_types=1);

namespace App\Messaging;

interface ChannelSender
{
    /** Plain text only (no parse mode): model output must never be interpreted as markup. */
    public function sendText(string $accountId, string $chatId, string $text): void;

    public function typing(string $accountId, string $chatId): void;

    public function answerCallback(string $accountId, string $callbackId, string $text): void;

    /** @return string|null file bytes, or null when too big / unavailable */
    public function download(string $accountId, string $fileId, int $maxBytes): ?string;
}
