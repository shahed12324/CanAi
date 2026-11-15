<?php

declare(strict_types=1);

namespace Jarvis\Connector;

interface NonceStore
{
    /** @return bool true the first time a nonce is seen within the window, false on replay */
    public function remember(string $nonce, int $ttlSeconds): bool;
}
