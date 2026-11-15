<?php

declare(strict_types=1);

namespace App\Reports;

interface ReportRepo
{
    /**
     * Per-campaign totals of an ad platform over [from, to] (inclusive days), campaigns with no activity left out.  With a client id only that client's accounts.  Copied data only — nothing is fetched from Meta / Google here.
     *
     * @param  'meta'|'google'  $platform
     * @return list<array{account_id: string, account: string, currency: string, synced_at: ?string, campaign: string, name: string, spend: string, impressions: int, clicks: int, results: string}>
     */
    public function ads(string $platform, string $from, string $to, ?string $clientId = null): array;

    /**
     * Social posts posted in [from, to] (in the given zone) and the newest engagement snapshot of each.
     *
     * @return array{posts: list<array{platform: string, count: int}>, reactions: int, comments: int, shares: int, reach: int, top: ?array{title: string, platform: string, reactions: int}}
     */
    public function social(string $from, string $to, string $zone, ?string $clientId = null): array;

    /** @return ?array<string, mixed> the client's own brand kit when a client is given and has one, else the workspace's default kit */
    public function brand(?string $clientId = null): ?array;
}
