<?php

declare(strict_types=1);

namespace App\Inbox\Channels;

interface ChannelAdapter
{
    /** Longest single message the platform accepts. */
    public function maxLength(): int;

    /**
     * Sends one text message. Returns the platform's message id.
     *
     * @param  array<string, mixed>  $creds
     */
    public function send(array $creds, string $accountRef, string $to, string $text): string;
}
