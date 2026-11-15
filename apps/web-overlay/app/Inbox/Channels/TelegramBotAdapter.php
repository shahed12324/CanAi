<?php

declare(strict_types=1);

namespace App\Inbox\Channels;

use App\Outreach\HttpJson;

/** Customer-facing Telegram bot (one token per channel). The token is in the URL path (Telegram's design) so it is never put in an error message. */
final class TelegramBotAdapter implements ChannelAdapter
{
    public function __construct(private readonly HttpJson $http, private readonly string $base = 'https://api.telegram.org') {}

    public function maxLength(): int
    {
        return 4000;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> Telegram's "result" */
    public function call(string $token, string $method, array $payload = []): array
    {
        if (preg_match('/\A\d{3,15}:[\w-]{20,80}\z/', $token) !== 1) {
            throw new ChannelException('bad bot token', true);
        }
        try {
            $r = $this->http->request('POST', "{$this->base}/bot$token/$method", ['Content-Type' => 'application/json', 'Accept' => 'application/json'], null, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new ChannelException('network error');
        }
        $s = $r['status'];
        if ($s === 429 || $s >= 500) {
            throw new ChannelException("HTTP $s");
        }
        if ($s < 200 || $s >= 300 || ($r['json']['ok'] ?? false) !== true) {
            $d = is_string($r['json']['description'] ?? null) ? mb_substr(str_replace($token, '***', $r['json']['description']), 0, 120) : '';
            throw new ChannelException("Telegram $method HTTP $s $d", true);
        }

        return is_array($r['json']['result'] ?? null) ? $r['json']['result'] : [];
    }

    public function send(array $creds, string $accountRef, string $to, string $text): string
    {
        if (preg_match('/\A-?\d{1,20}\z/', $to) !== 1) {
            throw new ChannelException('bad recipient', true);
        }
        $r = $this->call((string) ($creds['bot_token'] ?? ''), 'sendMessage', ['chat_id' => $to, 'text' => $text, 'disable_web_page_preview' => true]);

        return (string) ($r['message_id'] ?? '');
    }
}
