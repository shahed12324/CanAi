<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Messaging\AttemptLimiter;

final class FakeLimiter implements AttemptLimiter
{
    /** @var array<string, int> */
    public array $hits = [];

    public function tooMany(string $key, int $max): bool
    {
        return ($this->hits[$key] ?? 0) >= $max;
    }

    public function hit(string $key, int $decaySeconds): void
    {
        $this->hits[$key] = ($this->hits[$key] ?? 0) + 1;
    }
}
