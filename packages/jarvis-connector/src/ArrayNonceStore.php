<?php

declare(strict_types=1);

namespace Jarvis\Connector;

final class ArrayNonceStore implements NonceStore
{
    /** @var array<string, int> */
    private array $seen = [];

    public function remember(string $nonce, int $ttlSeconds): bool
    {
        if (isset($this->seen[$nonce])) {
            return false;
        }
        $this->seen[$nonce] = $ttlSeconds;

        return true;
    }
}
