<?php

declare(strict_types=1);

namespace App\Ads;

use Illuminate\Support\Facades\DB;

final class PgAdsLaunchRepo implements AdsLaunchRepo
{
    public function claim(int $limit, int $hourlyCap): array
    {
        $rows = DB::connection('admin')->select('select draft_id, tenant_id, user_id, account_id, account_ext, currency, pixel_id, credentials_ct, key_version, page_ext, name, objective, country, age_min, age_max, gender, primary_text, headline, description, cta,
            link_url, daily_budget, days, start_date, remote_campaign, remote_adset, remote_creative, remote_ad from ads_launch_claim(?, ?)', [max(1, min(5, $limit)), max(0, $hourlyCap)]);
        $n = fn ($v) => $v === null ? null : (string) $v;

        return array_map(fn ($r) => ['id' => trim((string) $r->draft_id), 'tenant_id' => trim((string) $r->tenant_id), 'user_id' => trim((string) $r->user_id), 'account_id' => trim((string) $r->account_id), 'account_ext' => (string) $r->account_ext,
            'currency' => trim((string) $r->currency), 'pixel_id' => $n($r->pixel_id), 'cipher' => (string) $r->credentials_ct, 'version' => (int) $r->key_version, 'page_ext' => (string) $r->page_ext, 'name' => (string) $r->name, 'objective' => (string) $r->objective,
            'country' => trim((string) $r->country), 'age_min' => (int) $r->age_min, 'age_max' => (int) $r->age_max, 'gender' => (string) $r->gender, 'primary_text' => (string) $r->primary_text, 'headline' => (string) $r->headline,
            'description' => (string) $r->description, 'cta' => (string) $r->cta, 'link_url' => $n($r->link_url), 'daily_budget' => (string) $r->daily_budget, 'days' => (int) $r->days, 'start_date' => $n($r->start_date),
            'remote_campaign' => $n($r->remote_campaign), 'remote_adset' => $n($r->remote_adset), 'remote_creative' => $n($r->remote_creative), 'remote_ad' => $n($r->remote_ad)], $rows);
    }

    public function step(string $tenantId, string $draftId, string $field, string $remoteId): string
    {
        return (string) DB::connection('admin')->selectOne('select ads_launch_step(?, ?, ?, ?) as r', [$draftId, $tenantId, $field, $remoteId])->r;
    }

    public function done(string $tenantId, string $draftId): string
    {
        return (string) DB::connection('admin')->selectOne('select ads_launch_done(?, ?) as r', [$draftId, $tenantId])->r;
    }

    public function fail(string $tenantId, string $draftId, string $error, bool $unknown): string
    {
        return (string) DB::connection('admin')->selectOne('select ads_launch_fail(?, ?, ?, ?::boolean) as r', [$draftId, $tenantId, $error, $unknown ? 'true' : 'false'])->r;
    }
}
