<?php

declare(strict_types=1);

namespace App\GoogleAds;

use App\Ads\AdsAdvisor;

/**
 * Advice for Google campaigns: the same rules as Meta (AdsAdvisor) over the copied 7-day results of ENABLED campaigns.  Pure.
 * Two Google-specific guards, so the advice never invents a problem:
 *   * an account with NO conversions at all in the week is skipped — most likely conversion tracking is not set up, and "no results" would be a false alarm;
 *   * a fractional conversion count is rounded UP (0.4 conversions is "some result"), which only ever makes the advice more cautious.
 */
final class GadsAdvisor
{
    /**
     * @param  list<array{account_id: string, campaign: string, name: string, status: string, cost: string, clicks: int, conversions: string, days: int}>  $rows
     * @return list<array{kind: string, account_id: string, campaign: string, name: string, why: string}>
     */
    public static function advise(array $rows): array
    {
        $tracked = [];
        foreach ($rows as $r) {
            if ((float) $r['conversions'] > 0) {
                $tracked[$r['account_id']] = true;
            }
        }
        $mapped = [];
        foreach ($rows as $r) {
            if (! isset($tracked[$r['account_id']])) {
                continue;
            }
            $mapped[] = ['account_id' => $r['account_id'], 'campaign' => $r['campaign'], 'name' => $r['name'], 'status' => $r['status'] === 'ENABLED' ? 'ACTIVE' : $r['status'], 'spend' => $r['cost'],
                'clicks' => $r['clicks'], 'results' => (int) ceil((float) $r['conversions']), 'days' => $r['days']];
        }

        return AdsAdvisor::advise($mapped);
    }
}
