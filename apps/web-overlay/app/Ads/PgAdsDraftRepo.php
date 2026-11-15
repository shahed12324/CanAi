<?php

declare(strict_types=1);

namespace App\Ads;

use Illuminate\Support\Facades\DB;

final class PgAdsDraftRepo implements AdsDraftRepo
{
    private const SELECT = 'select d.id, d.user_id, u.name as author, d.account_id, a.name as account_name, a.currency, d.name, d.objective, d.country, d.age_min, d.age_max, d.gender, d.interests, d.primary_text, d.headline, d.description, d.cta, d.link_url,
        d.daily_budget, d.days, d.start_date, d.status, d.reject_note, p.name as approver, d.approved_at, d.updated_at, d.page_account_id, pg.name as page_name, d.launch_state, d.launch_error, d.launch_unknown, d.launched_at, d.remote_campaign, d.remote_adset, d.remote_creative, d.remote_ad from ads_drafts d left join social_accounts pg on pg.id = d.page_account_id and pg.tenant_id = d.tenant_id left join users u on u.id = d.user_id left join users p on p.id = d.approved_by left join ads_accounts a on a.id = d.account_id and a.tenant_id = d.tenant_id';

    private static function row(object $r): array
    {
        $n = fn ($v) => $v === null ? null : (string) $v;

        return ['id' => trim((string) $r->id), 'user_id' => trim((string) $r->user_id), 'author' => $n($r->author), 'account_id' => trim((string) $r->account_id), 'account_name' => $n($r->account_name), 'currency' => $r->currency === null ? null : trim((string) $r->currency),
            'name' => (string) $r->name, 'objective' => (string) $r->objective, 'country' => trim((string) $r->country), 'age_min' => (int) $r->age_min, 'age_max' => (int) $r->age_max, 'gender' => (string) $r->gender, 'interests' => $n($r->interests),
            'primary_text' => (string) $r->primary_text, 'headline' => (string) $r->headline, 'description' => (string) $r->description, 'cta' => (string) $r->cta, 'link_url' => $n($r->link_url), 'daily_budget' => (string) $r->daily_budget,
            'days' => (int) $r->days, 'start_date' => $n($r->start_date), 'status' => (string) $r->status, 'reject_note' => $n($r->reject_note), 'approver' => $n($r->approver), 'approved_at' => $n($r->approved_at), 'updated_at' => (string) $r->updated_at,
            'page_account_id' => $r->page_account_id === null ? null : trim((string) $r->page_account_id), 'page_name' => $n($r->page_name), 'launch_state' => $n($r->launch_state), 'launch_error' => $n($r->launch_error), 'launch_unknown' => (bool) $r->launch_unknown,
            'launched_at' => $n($r->launched_at), 'remote_campaign' => $n($r->remote_campaign), 'remote_adset' => $n($r->remote_adset), 'remote_creative' => $n($r->remote_creative), 'remote_ad' => $n($r->remote_ad)];
    }

    public function drafts(): array
    {
        return array_map(fn ($r) => self::row($r), DB::select(self::SELECT.' order by d.updated_at desc limit 100'));
    }

    public function find(string $id): ?array
    {
        $r = DB::selectOne(self::SELECT.' where d.id = ?', [$id]);

        return $r === null ? null : self::row($r);
    }

    public function caps(): ?array
    {
        $r = DB::selectOne('select max_daily, max_total from ads_settings');

        return $r === null ? null : ['max_daily' => (string) $r->max_daily, 'max_total' => (string) $r->max_total];
    }

    public function saveCaps(string $tenantId, string $userId, string $daily, string $total): string
    {
        return (string) DB::selectOne('select ads_settings_save(?, ?, ?::numeric, ?::numeric) as r', [$tenantId, $userId, $daily, $total])->r;
    }

    public function save(bool $new, string $tenantId, string $userId, string $id, array $v): string
    {
        return trim((string) DB::selectOne('select ads_draft_save(?::boolean, ?, ?, ?, ?, ?, ?, ?, ?::integer, ?::integer, ?, ?, ?, ?, ?, ?, ?, ?::numeric, ?::integer, ?::date) as r', [$new ? 'true' : 'false', $id, $tenantId, $userId, $v['account_id'], $v['name'], $v['objective'], $v['country'],
            $v['age_min'], $v['age_max'], $v['gender'], $v['interests'], $v['primary_text'], $v['headline'], $v['description'], $v['cta'], $v['link_url'], $v['daily_budget'], $v['days'], $v['start_date']])->r);
    }

    public function setPage(string $tenantId, string $userId, string $id, ?string $pageAccountId): string
    {
        return (string) DB::selectOne('select ads_draft_set_page(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $pageAccountId])->r;
    }

    public function retryLaunch(string $tenantId, string $userId, string $id, bool $confirm): string
    {
        return (string) DB::selectOne('select ads_launch_retry(?, ?, ?, ?::boolean) as r', [$id, $tenantId, $userId, $confirm ? 'true' : 'false'])->r;
    }

    public function submit(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select ads_draft_submit(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function decide(string $tenantId, string $approverId, string $id, bool $approve, ?string $note): string
    {
        return (string) DB::selectOne('select ads_draft_decide(?, ?, ?, ?::boolean, ?) as r', [$id, $tenantId, $approverId, $approve ? 'true' : 'false', $note])->r;
    }

    public function reopen(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select ads_draft_reopen(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select ads_draft_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }
}
