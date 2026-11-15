<?php

declare(strict_types=1);

namespace App\Links;

use App\Ai\CredentialVault;

final class VaultSecrets implements Secrets
{
    public function __construct(private readonly CredentialVault $vault) {}

    public function encrypt(string $plain): array
    {
        return $this->vault->encrypt($plain);
    }

    public function decrypt(string $cipher, int $version): string
    {
        return $this->vault->decrypt($cipher, $version);
    }
}
