<?php

declare(strict_types=1);

namespace App\Social;

use App\Ai\CredentialVault;

/** Seals a Page token with the platform vault; opens it only in memory, only in the worker. */
final class SocialSecrets implements TokenSealer
{
    public function __construct(private readonly CredentialVault $vault) {}

    /** @return array{cipher: string, version: int} */
    public function seal(string $token): array
    {
        return $this->vault->encrypt(json_encode(['token' => $token], JSON_THROW_ON_ERROR));
    }

    public function open(string $cipher, int $version): ?string
    {
        try {
            $a = json_decode($this->vault->decrypt($cipher, $version), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($a) && is_string($a['token'] ?? null) && $a['token'] !== '' ? $a['token'] : null;
    }
}
