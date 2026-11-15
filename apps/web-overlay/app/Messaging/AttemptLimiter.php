<?php

declare(strict_types=1);

namespace App\Messaging;

interface AttemptLimiter
{
    public function tooMany(string $key, int $max): bool;

    public function hit(string $key, int $decaySeconds): void;
}
