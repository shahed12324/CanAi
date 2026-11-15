<?php

declare(strict_types=1);

namespace App\Ai;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * Encrypts provider API keys with AES-256-GCM using a dedicated, versioned key (not APP_KEY).
 * The database only ever stores the ciphertext plus the key version used.
 * Nothing here logs, and the instance hides its keys from dumps/var_dump.
 */
final class CredentialVault
{
    /** @var array<int, Encrypter> */
    private array $encrypters = [];

    /** @param array<int, string> $keys version => "base64:..." (32 random bytes) */
    public function __construct(array $keys, private readonly int $current)
    {
        if (! isset($keys[$current])) {
            throw new \InvalidArgumentException('AI vault: the current key version has no key configured.');
        }
        foreach ($keys as $version => $key) {
            $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;
            if ($raw === false || strlen($raw) !== 32) {
                throw new \InvalidArgumentException("AI vault: key v{$version} must be base64:<32 random bytes>.");
            }
            $this->encrypters[$version] = new Encrypter($raw, 'aes-256-gcm');
        }
    }

    public static function generateKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    public function currentVersion(): int
    {
        return $this->current;
    }

    /** @return array{cipher: string, version: int} */
    public function encrypt(string $plain): array
    {
        if ($plain === '') {
            throw new \InvalidArgumentException('Empty credential.');
        }

        return ['cipher' => $this->encrypters[$this->current]->encryptString($plain), 'version' => $this->current];
    }

    public function decrypt(string $cipher, int $version): string
    {
        $enc = $this->encrypters[$version] ?? throw new \RuntimeException("AI vault: no key for version {$version}.");
        try {
            return $enc->decryptString($cipher);
        } catch (DecryptException) {
            // Deliberately vague and without the ciphertext.
            throw new \RuntimeException('AI vault: credential could not be decrypted.');
        }
    }

    public function needsRotation(int $version): bool
    {
        return $version !== $this->current;
    }

    /** @return array<string, never> */
    public function __debugInfo(): array
    {
        return [];
    }
}
