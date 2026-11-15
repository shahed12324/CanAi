<?php

declare(strict_types=1);

namespace App\Developer;

/**
 * Signs a webhook so the receiver can tell it came from JARVIS and was not changed:
 *   X-Jarvis-Timestamp: <unix seconds>      X-Jarvis-Signature: v1=<hex hmac-sha256 of "<timestamp>.<body>">
 * The receiver should also reject timestamps older than a few minutes.
 */
final class WebhookSigner
{
    public static function sign(string $secret, int $timestamp, string $body): string
    {
        return 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }

    /** @return array<string, string> */
    public static function headers(string $secret, int $timestamp, string $body, string $event, string $deliveryId): array
    {
        return ['Content-Type' => 'application/json', 'User-Agent' => 'JARVIS-Webhook/1', 'X-Jarvis-Event' => $event, 'X-Jarvis-Delivery' => $deliveryId,
            'X-Jarvis-Timestamp' => (string) $timestamp, 'X-Jarvis-Signature' => self::sign($secret, $timestamp, $body)];
    }
}
