<?php

declare(strict_types=1);

namespace App\Outreach;

/** A new sending address starts small and grows: 5 mails on day one, +3 each day, never above the account's own daily limit. */
final class Warmup
{
    public const START = 5;

    public const STEP = 3;

    public static function limitFor(int $max, string $startedOn, string $today): int
    {
        $days = max(0, (int) floor((strtotime($today.' UTC') - strtotime($startedOn.' UTC')) / 86400));

        return max(0, min($max, self::START + self::STEP * $days));
    }
}
