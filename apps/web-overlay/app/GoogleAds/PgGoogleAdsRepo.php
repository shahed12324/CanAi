<?php

declare(strict_types=1);

namespace App\GoogleAds;

use Illuminate\Support\Facades\DB;

final class PgGoogleAdsRepo implements GoogleAdsRepo
{
    public function accounts(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'name' => (string) $r->name, 'customer_id' => trim((string) $r->customer_id), 'login_customer_id' => $r->login_customer_id === null ? null : trim((string) $r->login_customer_id), 'currency' => trim((string) $r->currency),
            'status' => (string) $r->status, 'last_synced_at' => $r->last_synced_at === null ? null : (string) $r->last_synced_at, 'sync_error' => $r->sync_error === null ? null : (string) $r->sync_error],
            DB::select('select id, name, customer_id, login_customer_id, currency, status, last_synced_at, sync_error from gads_accounts order by name'));
    }

    public function saveAccount(string $tenantId, string $id, string $name, string $customerId, ?string $loginId, string $currency, string $cipher, int $version): string
    {
        return trim((string) DB::selectOne('select gads_account_save(?, ?, ?, ?, ?, ?, ?, ?::smallint) as r', [$id, $tenantId, $name, $customerId, $loginId, $currency, $cipher, $version])->r);
    }

    public function deleteAccount(string $tenantId, string $id): string
    {
        return (string) DB::selectOne('select gads_account_delete(?, ?) as r', [$id, $tenantId])->r;
    }

    public function summary(int $days): array
    {
        $rows = DB::select('select c.account_id, c.external_id, c.name, c.status, c.channel, coalesce(sum(i.cost), 0) as cost, coalesce(sum(i.impressions), 0) as impressions, coalesce(sum(i.clicks), 0) as clicks, coalesce(sum(i.conversions), 0) as conversions
            from gads_campaigns c left join gads_insights_daily i on i.account_id = c.account_id and i.campaign_ext = c.external_id and i.day >= current_date - make_interval(days => ?)
            group by c.account_id, c.external_id, c.name, c.status, c.channel order by cost desc, c.name limit 300', [max(1, min(90, $days))]);

        return array_map(fn ($r) => ['account_id' => trim((string) $r->account_id), 'campaign' => (string) $r->external_id, 'name' => (string) $r->name, 'status' => (string) $r->status, 'channel' => $r->channel === null ? null : (string) $r->channel,
            'cost' => (string) $r->cost, 'impressions' => (int) $r->impressions, 'clicks' => (int) $r->clicks, 'conversions' => (string) $r->conversions], $rows);
    }

    public function syncDue(int $limit, int $minutes): array
    {
        return array_map(fn ($r) => ['account_id' => trim((string) $r->account_id), 'tenant_id' => trim((string) $r->tenant_id), 'customer_id' => trim((string) $r->customer_id), 'login_id' => $r->login_customer_id === null ? null : trim((string) $r->login_customer_id),
            'cipher' => (string) $r->credentials_ct, 'version' => (int) $r->key_version],
            DB::connection('admin')->select('select account_id, tenant_id, customer_id, login_customer_id, credentials_ct, key_version from gads_sync_due(?, ?)', [max(1, min(10, $limit)), max(5, $minutes)]));
    }

    public function store(string $tenantId, string $accountId, array $campaigns, array $insights): string
    {
        return (string) DB::connection('admin')->selectOne('select gads_sync_store(?, ?, ?::jsonb, ?::jsonb) as r', [$accountId, $tenantId, json_encode($campaigns, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($insights, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)])->r;
    }

    public function fail(string $tenantId, string $accountId, string $note, bool $invalid): string
    {
        return (string) DB::connection('admin')->selectOne('select gads_sync_fail(?, ?, ?, ?::boolean) as r', [$accountId, $tenantId, $note, $invalid ? 'true' : 'false'])->r;
    }
}
