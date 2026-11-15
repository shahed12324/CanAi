<?php

declare(strict_types=1);

namespace Jarvis\Connector;

/**
 * Request signing shared by JARVIS ONE and this package (keep both copies identical).
 *
 *   signature = hex( HMAC-SHA256( secret, timestamp "\n" nonce "\n" METHOD "\n" path?query "\n" sha256(body) ) )
 *
 * Sent as X-Jarvis-Timestamp (unix seconds), X-Jarvis-Nonce (random, single use) and X-Jarvis-Signature.
 */
final class Signature
{
    public const MAX_SKEW = 300;

    public static function sign(string $secret, string $timestamp, string $nonce, string $method, string $pathWithQuery, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [$timestamp, $nonce, strtoupper($method), $pathWithQuery, hash('sha256', $body)]), $secret);
    }

    public static function valid(string $secret, string $signature, string $timestamp, string $nonce, string $method, string $pathWithQuery, string $body): bool
    {
        return $secret !== '' && $signature !== '' && hash_equals(self::sign($secret, $timestamp, $nonce, $method, $pathWithQuery, $body), strtolower($signature));
    }

    public static function fresh(string $timestamp, int $now): bool
    {
        return preg_match('/\A\d{9,12}\z/', $timestamp) === 1 && abs($now - (int) $timestamp) <= self::MAX_SKEW;
    }

    public static function nonceOk(string $nonce): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{16,64}\z/', $nonce) === 1;
    }
}
