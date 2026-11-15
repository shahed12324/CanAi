<?php

declare(strict_types=1);

namespace App\Services\AiEngine;

/**
 * Builds the authentication headers the AI engine (Python) requires.
 * Pure PHP, no framework dependency, so it can be tested in isolation.
 *
 * Protocol is documented in apps/ai-engine/app/security.py and ARCHITECTURE.md.
 */
final class RequestSigner
{
    public function __construct(
        private readonly string $hmacSecret,
        private readonly string $jwtSecret,
        private readonly string $issuer = 'jarvis-web',
        private readonly string $audience = 'jarvis-ai-engine',
        private readonly int $ttlSeconds = 60,
    ) {
        if (strlen($hmacSecret) < 32 || strlen($jwtSecret) < 32) {
            throw new \InvalidArgumentException('AI engine secrets must be at least 32 characters.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function headers(
        string $method,
        string $path,
        string $query,
        string $body,
        string $tenantId,
        ?string $userId = null,
        ?int $now = null,
        ?string $nonce = null,
    ): array {
        $now ??= time();
        $nonce ??= bin2hex(random_bytes(16));

        $claims = [
            'iss' => $this->issuer,
            'aud' => $this->audience,
            'iat' => $now,
            'exp' => $now + $this->ttlSeconds,
            'jti' => $nonce,
            'tid' => $tenantId,
        ];
        if ($userId !== null) {
            $claims['uid'] = $userId;
        }

        return [
            'Authorization' => 'Bearer '.$this->jwt($claims),
            'X-Timestamp' => (string) $now,
            'X-Nonce' => $nonce,
            'X-Signature' => hash_hmac(
                'sha256',
                self::canonical($method, $path, $query, (string) $now, $nonce, $body),
                $this->hmacSecret,
            ),
        ];
    }

    public static function canonical(
        string $method,
        string $path,
        string $query,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        $parts = $query === '' ? [] : explode('&', $query);
        sort($parts, SORT_STRING);

        return implode("\n", [
            strtoupper($method),
            $path,
            implode('&', $parts),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    /** @param array<string, mixed> $claims */
    private function jwt(array $claims): string
    {
        $b64 = static fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = $b64(json_encode($claims, JSON_THROW_ON_ERROR));

        return "$head.$payload.".$b64(hash_hmac('sha256', "$head.$payload", $this->jwtSecret, true));
    }
}
