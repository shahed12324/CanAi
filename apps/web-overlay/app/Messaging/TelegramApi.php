<?php

declare(strict_types=1);

namespace App\Messaging;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/** Thin Bot API client. The token is part of every URL, so every error is scrubbed before it can reach a log or a screen. */
final class TelegramApi
{
    public function __construct(private readonly string $base = 'https://api.telegram.org', private readonly int $timeout = 15) {}

    /** @param array<string, mixed> $payload @return array<string, mixed> the "result" object */
    public function call(string $token, string $method, array $payload = []): array
    {
        try {
            $res = Http::timeout($this->timeout)->asJson()->post("{$this->base}/bot{$token}/{$method}", $payload);
            $json = $res->json();
        } catch (ConnectionException|RequestException $e) {
            throw new ChannelSendException(self::scrub($e->getMessage(), $token), 0);
        }
        if (! is_array($json) || ($json['ok'] ?? false) !== true) {
            $desc = is_array($json) ? (string) ($json['description'] ?? 'unknown error') : 'bad response (HTTP '.$res->status().')';
            throw new ChannelSendException(self::scrub("Telegram {$method}: {$desc}", $token), (int) ($json['error_code'] ?? $res->status()));
        }

        return is_array($json['result'] ?? null) ? $json['result'] : [];
    }

    /** @return string|null raw bytes */
    public function file(string $token, string $filePath): ?string
    {
        try {
            $res = Http::timeout($this->timeout)->get("{$this->base}/file/bot{$token}/".ltrim($filePath, '/'));
        } catch (ConnectionException|RequestException $e) {
            throw new ChannelSendException(self::scrub($e->getMessage(), $token), 0);
        }

        return $res->successful() ? $res->body() : null;
    }

    public static function scrub(string $text, string $token): string
    {
        $text = $token === '' ? $text : str_replace($token, '[token]', $text);

        return (string) preg_replace('/bot\d{6,12}:[A-Za-z0-9_-]{20,}/', 'bot[token]', $text);
    }
}
