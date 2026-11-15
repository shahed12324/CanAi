<?php

declare(strict_types=1);

namespace Jarvis\Connector;

use Illuminate\Support\Facades\Cache;

/** Uses the store's cache; `add` is atomic, so a replayed request loses the race. Use a shared cache (file/redis/database), not `array`. */
final class CacheNonceStore implements NonceStore
{
    public function remember(string $nonce, int $ttlSeconds): bool
    {
        return Cache::add('jarvis-connector:nonce:'.$nonce, 1, $ttlSeconds);
    }
}
