<?php

declare(strict_types=1);

namespace App\Ads;

/**
 * Rule-based optimisation advice from the copied 7-day results of ACTIVE campaigns (no AI, no cost, no guessing beyond these rules).  Pure.
 *   pause : enough traffic (≥ MIN_CLICKS clicks, ≥ MIN_DAYS days with spend) but zero results; or cost per result ≥ EXPENSIVE × the average of the OTHER campaigns of the same account.
 *   scale : ≥ MIN_RESULTS results, cost per result ≤ CHEAP × the others' average.  Advice only — this system never raises a budget.
 * "Others' average" needs at least 2 other campaigns with results in the same account; otherwise no comparison is made (never invent a benchmark).
 */
final class AdsAdvisor
{
    public const MIN_DAYS = 3;

    public const MIN_CLICKS = 30;

    public const MIN_RESULTS = 5;

    public const EXPENSIVE = 2.5;

    public const CHEAP = 0.6;

    /**
     * @param  list<array{account_id: string, campaign: string, name: string, status: string, spend: string, clicks: int, results: int, days: int}>  $rows
     * @return list<array{kind: string, account_id: string, campaign: string, name: string, why: string}>  pause first, then scale
     */
    public static function advise(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['status'] !== 'ACTIVE' || $r['days'] < self::MIN_DAYS) {
                continue;
            }
            $spend = (float) $r['spend'];
            if ($spend <= 0) {
                continue;
            }
            $make = fn (string $kind, string $why) => ['kind' => $kind, 'account_id' => $r['account_id'], 'campaign' => $r['campaign'], 'name' => $r['name'], 'why' => $why];
            if ($r['results'] === 0) {
                if ($r['clicks'] >= self::MIN_CLICKS) {
                    $out[] = $make('pause', sprintf('In the last 7 days %s spend and %d clicks, but no results.', number_format($spend, 2), $r['clicks']));
                }

                continue;
            }
            $avg = self::othersAverage($rows, $r);
            if ($avg === null) {
                continue;
            }
            $cpa = $spend / $r['results'];
            if ($cpa >= $avg * self::EXPENSIVE) {
                $out[] = $make('pause', sprintf('Cost per result %s — %.1f times the average %s of the others in the same account.', number_format($cpa, 2), number_format($avg, 2), $cpa / $avg));
            } elseif ($r['results'] >= self::MIN_RESULTS && $cpa <= $avg * self::CHEAP) {
                $out[] = $make('scale', sprintf('Cost per result %s — much lower than the others’ average %s (%d results).', number_format($cpa, 2), number_format($avg, 2), $r['results']));
            }
        }
        usort($out, fn ($a, $b) => [$a['kind'] === 'pause' ? 0 : 1] <=> [$b['kind'] === 'pause' ? 0 : 1]);

        return $out;
    }

    /** @param  array<string, mixed>  $me */
    private static function othersAverage(array $rows, array $me): ?float
    {
        $n = 0;
        $spend = 0.0;
        $results = 0;
        foreach ($rows as $o) {
            if ($o['account_id'] !== $me['account_id'] || $o['campaign'] === $me['campaign'] || $o['results'] <= 0 || (float) $o['spend'] <= 0) {
                continue;
            }
            $n++;
            $spend += (float) $o['spend'];
            $results += $o['results'];
        }

        return $n >= 2 ? $spend / $results : null;
    }
}
