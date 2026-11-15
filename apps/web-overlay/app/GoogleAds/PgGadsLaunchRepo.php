<?php

declare(strict_types=1);

namespace App\GoogleAds;

use Illuminate\Support\Facades\DB;

final class PgGadsLaunchRepo implements GadsLaunchRepo
{
    public function claim(int $limit, int $hourlyCap): array
    {
        $rows = DB::connection('admin')->select('select draft_id, tenant_id, account_id, customer_id, login_customer_id, currency, credentials_ct, key_version, name, country, language, final_url, headlines, descriptions, keywords, negatives,
            daily_budget, days, start_date, remote_budget, remote_campaign, remote_adgroup, remote_ad, keywords_done, targeting_done from gads_launch_claim(?, ?)', [max(1, min(5, $limit)), max(0, $hourlyCap)]);
        $n = fn ($v) => $v === null ? null : (string) $v;
        $arr = fn ($v) => is_array($j = is_string($v) ? json_decode($v, true) : $v) ? array_values($j) : [];

        return array_map(fn ($r) => ['id' => trim((string) $r->draft_id), 'tenant_id' => trim((string) $r->tenant_id), 'account_id' => trim((string) $r->account_id), 'customer_id' => trim((string) $r->customer_id),
            'login_id' => $r->login_customer_id === null ? null : trim((string) $r->login_customer_id), 'currency' => trim((string) $r->currency), 'cipher' => (string) $r->credentials_ct, 'version' => (int) $r->key_version,
            'name' => (string) $r->name, 'country' => trim((string) $r->country), 'language' => (string) $r->language, 'final_url' => $n($r->final_url), 'headlines' => $arr($r->headlines), 'descriptions' => $arr($r->descriptions),
            'keywords' => $arr($r->keywords), 'negatives' => $arr($r->negatives), 'daily_budget' => (string) $r->daily_budget, 'days' => (int) $r->days, 'start_date' => $n($r->start_date),
            'remote_budget' => $n($r->remote_budget), 'remote_campaign' => $n($r->remote_campaign), 'remote_adgroup' => $n($r->remote_adgroup), 'remote_ad' => $n($r->remote_ad),
            'keywords_done' => (bool) $r->keywords_done, 'targeting_done' => (bool) $r->targeting_done], $rows);
    }

    public function step(string $tenantId, string $draftId, string $field, string $value): string
    {
        return (string) DB::connection('admin')->selectOne('select gads_launch_step(?, ?, ?, ?) as r', [$draftId, $tenantId, $field, $value])->r;
    }

    public function done(string $tenantId, string $draftId): string
    {
        return (string) DB::connection('admin')->selectOne('select gads_launch_done(?, ?) as r', [$draftId, $tenantId])->r;
    }

    public function fail(string $tenantId, string $draftId, string $error, bool $unknown): string
    {
        return (string) DB::connection('admin')->selectOne('select gads_launch_fail(?, ?, ?, ?::boolean) as r', [$draftId, $tenantId, $error, $unknown ? 'true' : 'false'])->r;
    }
}
