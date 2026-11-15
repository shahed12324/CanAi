<?php

declare(strict_types=1);

namespace App\Messaging;

use Illuminate\Support\Facades\RateLimiter;

final class CacheAttemptLimiter implements AttemptLimiter
{
    public function tooMany(string $key, int $max): bool
    {
        return RateLimiter::tooManyAttempts('msg:'.$key, $max);
    }

    public function hit(string $key, int $decaySeconds): void
    {
        RateLimiter::hit('msg:'.$key, $decaySeconds);
    }
}
