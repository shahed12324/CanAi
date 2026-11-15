<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Links\Secrets;

final class FakeSecrets implements Secrets
{
    public bool $failDecrypt = false;

    public function encrypt(string $plain): array
    {
        return ['cipher' => 'ENC('.strrev($plain).')', 'version' => 1];
    }

    public function decrypt(string $cipher, int $version): string
    {
        if ($this->failDecrypt) {
            throw new \RuntimeException('no');
        }

        return strrev(substr($cipher, 4, -1));
    }
}
