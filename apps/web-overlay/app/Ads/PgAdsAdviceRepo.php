<?php

declare(strict_types=1);

namespace App\Ads;

use Illuminate\Support\Facades\DB;

final class PgAdsAdviceRepo implements AdsAdviceRepo
{
    public function stats(): array
    {
        $rows = DB::select("select c.account_id, c.external_id, c.name, c.status, coalesce(sum(i.spend), 0) as spend, coalesce(sum(i.clicks), 0) as clicks, coalesce(sum(i.results), 0) as results, count(i.day) filter (where i.spend > 0) as days
            from ads_campaigns c left join ads_insights_daily i on i.account_id = c.account_id and i.campaign_ext = c.external_id and i.day >= current_date - 7
            where c.status = 'ACTIVE' group by c.account_id, c.external_id, c.name, c.status order by c.name limit 300");

        return array_map(fn ($r) => ['account_id' => trim((string) $r->account_id), 'campaign' => (string) $r->external_id, 'name' => (string) $r->name, 'status' => (string) $r->status, 'spend' => (string) $r->spend, 'clicks' => (int) $r->clicks, 'results' => (int) $r->results, 'days' => (int) $r->days], $rows);
    }

    public function requestPause(string $tenantId, string $userId, string $accountId, string $campaign, string $reason): string
    {
        return (string) DB::selectOne('select ads_pause_request(?, ?, ?, ?, ?, ?) as r', [strtolower(\App\Actions\Tenancy\CreateWorkspace::newId()), $tenantId, $userId, $accountId, $campaign, $reason])->r;
    }

    public function requests(): array
    {
        return array_map(fn ($r) => ['account_id' => trim((string) $r->account_id), 'campaign' => (string) $r->campaign_ext, 'name' => (string) $r->campaign_name, 'status' => (string) $r->status, 'error' => $r->error === null ? null : (string) $r->error, 'created_at' => (string) $r->created_at],
            DB::select('select account_id, campaign_ext, campaign_name, status, error, created_at from ads_pause_requests order by created_at desc limit 10'));
    }

    public function claim(int $limit): array
    {
        return array_map(fn ($r) => ['req_id' => trim((string) $r->req_id), 'tenant_id' => trim((string) $r->tenant_id), 'account_id' => trim((string) $r->account_id), 'account_ext' => (string) $r->account_ext, 'campaign' => (string) $r->campaign_ext, 'cipher' => (string) $r->credentials_ct, 'version' => (int) $r->key_version],
            DB::connection('admin')->select('select req_id, tenant_id, account_id, account_ext, campaign_ext, credentials_ct, key_version from ads_pause_claim(?)', [max(1, min(10, $limit))]));
    }

    public function done(string $tenantId, string $reqId, bool $ok, string $error): string
    {
        return (string) DB::connection('admin')->selectOne('select ads_pause_done(?, ?, ?::boolean, ?) as r', [$reqId, $tenantId, $ok ? 'true' : 'false', $error])->r;
    }
}
