<?php

declare(strict_types=1);

namespace App\GoogleAds;

use Illuminate\Support\Facades\DB;

final class PgGadsAdviceRepo implements GadsAdviceRepo
{
    public function stats(): array
    {
        $rows = DB::select("select c.account_id, c.external_id, c.name, c.status, coalesce(sum(i.cost), 0) as cost, coalesce(sum(i.clicks), 0) as clicks, coalesce(sum(i.conversions), 0) as conversions, count(i.day) filter (where i.cost > 0) as days
            from gads_campaigns c left join gads_insights_daily i on i.account_id = c.account_id and i.campaign_ext = c.external_id and i.day >= current_date - 7
            where c.status = 'ENABLED' group by c.account_id, c.external_id, c.name, c.status order by c.name limit 300");

        return array_map(fn ($r) => ['account_id' => trim((string) $r->account_id), 'campaign' => (string) $r->external_id, 'name' => (string) $r->name, 'status' => (string) $r->status, 'cost' => (string) $r->cost, 'clicks' => (int) $r->clicks, 'conversions' => (string) $r->conversions, 'days' => (int) $r->days], $rows);
    }

    public function requestPause(string $tenantId, string $userId, string $accountId, string $campaign, string $reason): string
    {
        return (string) DB::selectOne('select gads_pause_request(?, ?, ?, ?, ?, ?) as r', [strtolower(\App\Actions\Tenancy\CreateWorkspace::newId()), $tenantId, $userId, $accountId, $campaign, $reason])->r;
    }

    public function requests(): array
    {
        return array_map(fn ($r) => ['account_id' => trim((string) $r->account_id), 'campaign' => (string) $r->campaign_ext, 'name' => (string) $r->campaign_name, 'status' => (string) $r->status, 'error' => $r->error === null ? null : (string) $r->error, 'created_at' => (string) $r->created_at],
            DB::select('select account_id, campaign_ext, campaign_name, status, error, created_at from gads_pause_requests order by created_at desc limit 10'));
    }

    public function claim(int $limit): array
    {
        return array_map(fn ($r) => ['req_id' => trim((string) $r->req_id), 'tenant_id' => trim((string) $r->tenant_id), 'account_id' => trim((string) $r->account_id), 'customer_id' => trim((string) $r->customer_id),
            'login_id' => $r->login_customer_id === null ? null : trim((string) $r->login_customer_id), 'campaign' => (string) $r->campaign_ext, 'cipher' => (string) $r->credentials_ct, 'version' => (int) $r->key_version],
            DB::connection('admin')->select('select req_id, tenant_id, account_id, customer_id, login_customer_id, campaign_ext, credentials_ct, key_version from gads_pause_claim(?)', [max(1, min(10, $limit))]));
    }

    public function done(string $tenantId, string $reqId, bool $ok, string $error): string
    {
        return (string) DB::connection('admin')->selectOne('select gads_pause_done(?, ?, ?::boolean, ?) as r', [$reqId, $tenantId, $ok ? 'true' : 'false', $error])->r;
    }
}
