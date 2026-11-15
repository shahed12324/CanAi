<?php

declare(strict_types=1);

namespace App\GoogleAds;

use Illuminate\Support\Facades\DB;

final class PgGadsDraftRepo implements GadsDraftRepo
{
    private const SELECT = 'select d.id, d.user_id, u.name as author, d.account_id, a.name as account_name, a.currency, d.name, d.country, d.language, d.final_url, d.headlines, d.descriptions, d.keywords, d.negatives, d.daily_budget, d.days, d.start_date,
        d.status, d.reject_note, p.name as approver, d.approved_at, d.updated_at, d.launch_state, d.launch_error, d.launch_unknown, d.launched_at, d.remote_budget, d.remote_campaign, d.remote_adgroup, d.remote_ad from gads_drafts d left join users u on u.id = d.user_id left join users p on p.id = d.approved_by left join gads_accounts a on a.id = d.account_id and a.tenant_id = d.tenant_id';

    /** @return list<mixed> */
    private static function arr(mixed $v): array
    {
        $j = is_string($v) ? json_decode($v, true) : $v;

        return is_array($j) ? array_values($j) : [];
    }

    private static function row(object $r): array
    {
        $n = fn ($v) => $v === null ? null : (string) $v;

        return ['id' => trim((string) $r->id), 'user_id' => trim((string) $r->user_id), 'author' => $n($r->author), 'account_id' => trim((string) $r->account_id), 'account_name' => $n($r->account_name), 'currency' => $r->currency === null ? null : trim((string) $r->currency),
            'name' => (string) $r->name, 'country' => trim((string) $r->country), 'language' => (string) $r->language, 'final_url' => $n($r->final_url), 'headlines' => array_map('strval', self::arr($r->headlines)), 'descriptions' => array_map('strval', self::arr($r->descriptions)),
            'keywords' => array_map(fn ($k) => ['t' => (string) ($k['t'] ?? ''), 'm' => (string) ($k['m'] ?? 'phrase')], self::arr($r->keywords)), 'negatives' => array_map('strval', self::arr($r->negatives)),
            'daily_budget' => (string) $r->daily_budget, 'days' => (int) $r->days, 'start_date' => $n($r->start_date), 'status' => (string) $r->status, 'reject_note' => $n($r->reject_note), 'approver' => $n($r->approver), 'approved_at' => $n($r->approved_at), 'updated_at' => (string) $r->updated_at,
            'launch_state' => $n($r->launch_state), 'launch_error' => $n($r->launch_error), 'launch_unknown' => (bool) $r->launch_unknown, 'launched_at' => $n($r->launched_at), 'remote_budget' => $n($r->remote_budget),
            'remote_campaign' => $n($r->remote_campaign), 'remote_adgroup' => $n($r->remote_adgroup), 'remote_ad' => $n($r->remote_ad)];
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
        $r = DB::selectOne('select max_daily, max_total from gads_settings');

        return $r === null ? null : ['max_daily' => (string) $r->max_daily, 'max_total' => (string) $r->max_total];
    }

    public function saveCaps(string $tenantId, string $userId, string $daily, string $total): string
    {
        return (string) DB::selectOne('select gads_settings_save(?, ?, ?::numeric, ?::numeric) as r', [$tenantId, $userId, $daily, $total])->r;
    }

    public function save(bool $new, string $tenantId, string $userId, string $id, array $v): string
    {
        $j = fn ($x) => json_encode($x, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return trim((string) DB::selectOne('select gads_draft_save(?::boolean, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?::jsonb, ?::numeric, ?::integer, ?::date) as r', [$new ? 'true' : 'false', $id, $tenantId, $userId, $v['account_id'], $v['name'], $v['country'], $v['language'],
            $v['final_url'], $j($v['headlines']), $j($v['descriptions']), $j($v['keywords']), $j($v['negatives']), $v['daily_budget'], $v['days'], $v['start_date']])->r);
    }

    public function submit(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select gads_draft_submit(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function decide(string $tenantId, string $approverId, string $id, bool $approve, ?string $note): string
    {
        return (string) DB::selectOne('select gads_draft_decide(?, ?, ?, ?::boolean, ?) as r', [$id, $tenantId, $approverId, $approve ? 'true' : 'false', $note])->r;
    }

    public function reopen(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select gads_draft_reopen(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }

    public function retryLaunch(string $tenantId, string $userId, string $id, bool $confirm): string
    {
        return (string) DB::selectOne('select gads_launch_retry(?, ?, ?, ?::boolean) as r', [$id, $tenantId, $userId, $confirm ? 'true' : 'false'])->r;
    }

    public function delete(string $tenantId, string $userId, string $id): string
    {
        return (string) DB::selectOne('select gads_draft_delete(?, ?, ?) as r', [$id, $tenantId, $userId])->r;
    }
}
