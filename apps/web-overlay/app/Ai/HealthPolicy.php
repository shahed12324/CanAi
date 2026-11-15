<?php

declare(strict_types=1);

namespace App\Ai;

/** Pure status transitions for the scheduled health check. Never touches a provider an admin disabled. */
final class HealthPolicy
{
    public const FAIL_THRESHOLD = 3;

    /**
     * @param  list<bool>  $recent  newest first; true = check passed
     */
    public static function next(string $current, array $recent): string
    {
        if ($current === 'disabled' || $recent === []) {
            return $current;
        }
        if ($recent[0] === true) {
            return 'active';
        }
        $failures = 0;
        foreach ($recent as $ok) {
            if ($ok) {
                break;
            }
            $failures++;
        }

        return $failures >= self::FAIL_THRESHOLD ? 'degraded' : $current;
    }
}
