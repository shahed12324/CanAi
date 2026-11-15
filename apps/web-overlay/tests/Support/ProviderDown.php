<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Ai\ProviderCallException;

final class ProviderDown
{
    public static function make(): ProviderCallException
    {
        return new ProviderCallException('rate_limited', 'slow down', 429);
    }
}
