<?php

declare(strict_types=1);

namespace App\Catalog\Stores;

/** Identical rule to Jarvis\Connector\Signature in the shop package — a cross-test keeps the two in step. */
final class ConnectorSignature
{
    public static function sign(string $secret, string $timestamp, string $nonce, string $method, string $pathWithQuery, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [$timestamp, $nonce, strtoupper($method), $pathWithQuery, hash('sha256', $body)]), $secret);
    }
}
