<?php

declare(strict_types=1);

namespace App\Inbox\Channels;

/** Proves a webhook really comes from the platform. All comparisons are constant-time. */
final class WebhookVerifier
{
    /** Meta signs the RAW body with the app secret: header "X-Hub-Signature-256: sha256=<hex>". */
    public static function meta(string $rawBody, ?string $header, string $appSecret): bool
    {
        if ($appSecret === '' || $header === null || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $appSecret), strtolower(substr($header, 7)));
    }

    /** Telegram echoes the secret we gave setWebhook in X-Telegram-Bot-Api-Secret-Token. */
    public static function telegram(?string $header, string $secret): bool
    {
        return $secret !== '' && $header !== null && hash_equals($secret, $header);
    }

    /** Meta's one-time GET handshake. Returns the challenge to echo, or null. The verify token is derived from the channel key, so nothing extra is stored. */
    public static function challenge(?string $mode, ?string $token, ?string $challenge, string $publicKey): ?string
    {
        if ($mode !== 'subscribe' || $token === null || $challenge === null || ! hash_equals(self::verifyToken($publicKey), $token) || preg_match('/\A[\w.\-]{1,100}\z/', $challenge) !== 1) {
            return null;
        }

        return $challenge;
    }

    public static function verifyToken(string $publicKey): string
    {
        return substr(hash_hmac('sha256', 'jarvis-verify', $publicKey), 0, 24);
    }
}
