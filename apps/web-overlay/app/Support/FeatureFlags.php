<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class FeatureFlags
{
    /** Percentage rollouts are sticky per (flag, tenant): the same tenant always lands in the same bucket. */
    public static function enabled(string $key, ?string $tenantId = null): bool
    {
        $flag = self::all()[$key] ?? null;
        if ($flag === null || ! $flag['enabled']) {
            return false;
        }
        $percentage = $flag['percentage'];
        if ($percentage === null || $percentage >= 100) {
            return true;
        }
        if ($tenantId === null || $percentage <= 0) {
            return false;
        }

        return (crc32($key.'|'.$tenantId) % 100) < $percentage;
    }

    /** @return array<string, array{enabled: bool, percentage: int|null}> */
    public static function all(): array
    {
        try {
            return Cache::remember('feature_flags.v1', 30, function (): array {
                $out = [];
                foreach (DB::table('feature_flags')->get(['key', 'enabled', 'rollout']) as $row) {
                    $rollout = json_decode($row->rollout, true) ?: [];
                    $out[$row->key] = [
                        'enabled' => (bool) $row->enabled,
                        'percentage' => isset($rollout['percentage']) ? (int) $rollout['percentage'] : null,
                    ];
                }

                return $out;
            });
        } catch (\Throwable) {
            return [];
        }
    }

    public static function forget(): void
    {
        Cache::forget('feature_flags.v1');
    }
}
