<?php

declare(strict_types=1);

namespace App\Audit;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgAuditRepo implements AuditRepo
{
    private const DEFAULTS = ['dup_days' => 3, 'outlier_factor' => 5, 'min_history' => 5, 'backdate_days' => 30, 'cash_limit_minor' => 5000000, 'round_modulus' => 100000, 'round_min_minor' => 1000000];

    public function settings(): array
    {
        $r = DB::selectOne('select dup_days, outlier_factor, min_history, backdate_days, cash_limit_minor, round_modulus, round_min_minor from audit_settings');
        if ($r === null) {
            return self::DEFAULTS;
        }

        return array_map('intval', (array) $r);
    }

    public function saveSettings(string $tenantId, string $userId, array $v): string
    {
        return (string) DB::selectOne('select audit_settings_save(?, ?, ?::integer, ?::integer, ?::integer, ?::integer, ?::bigint, ?::bigint, ?::bigint) as r',
            [$tenantId, $userId, $v['dup_days'], $v['outlier_factor'], $v['min_history'], $v['backdate_days'], $v['cash_limit_minor'], $v['round_modulus'], $v['round_min_minor']])->r;
    }

    public function scan(string $tenantId, string $userId): int
    {
        return (int) DB::selectOne('select audit_scan(?, ?) as r', [$tenantId, $userId])->r;
    }

    public function findings(?string $status, int $limit): array
    {
        $rows = DB::select("select f.id, f.rule, f.severity, f.subject_type, f.subject_id, f.amount_minor, f.detail, f.status, f.note, f.found_at, f.reviewed_at, u.name as reviewer
            from audit_findings f left join users u on u.id = f.reviewed_by
            where (?::text is null or f.status = ?::text)
            order by case f.severity when 'high' then 0 when 'medium' then 1 else 2 end, f.found_at desc, f.id limit ?", [$status, $status, $limit]);

        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'rule' => (string) $r->rule, 'severity' => (string) $r->severity, 'subject_type' => (string) $r->subject_type, 'subject_id' => $r->subject_id === null ? null : trim((string) $r->subject_id),
            'amount_minor' => (int) $r->amount_minor, 'detail' => (array) json_decode((string) $r->detail, true), 'status' => (string) $r->status, 'note' => $r->note, 'found_at' => (string) $r->found_at, 'reviewed_at' => $r->reviewed_at, 'reviewer' => $r->reviewer], $rows);
    }

    public function counts(): array
    {
        $out = ['open' => 0, 'confirmed' => 0, 'dismissed' => 0];
        foreach (DB::select('select status, count(*) as c from audit_findings group by status') as $r) {
            $out[(string) $r->status] = (int) $r->c;
        }

        return $out;
    }

    public function review(string $tenantId, string $userId, string $id, string $status, ?string $note): string
    {
        return (string) DB::selectOne('select audit_review(?, ?, ?, ?, ?) as r', [$id, $tenantId, $userId, $status, $note])->r;
    }

    public function health(string $tenantId): array
    {
        return array_map(fn ($r) => ['key' => (string) $r->check_key, 'ok' => (bool) $r->ok, 'count' => (int) $r->cnt, 'amount_minor' => (int) $r->amt], DB::select('select check_key, ok, cnt, amt from audit_health(?)', [$tenantId]));
    }

    public function checklist(string $period): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'label' => (string) $r->label, 'sort' => (int) $r->sort, 'active' => (bool) $r->active, 'done_on' => $r->done_on === null ? null : (string) $r->done_on, 'note' => $r->note],
            DB::select('select i.id, i.label, i.sort, i.active, d.done_on, d.note from audit_items i left join audit_item_done d on d.item_id = i.id and d.period = ? order by i.active desc, i.sort, i.label, i.id', [$period]));
    }

    public function saveItem(string $tenantId, string $userId, ?string $id, string $label, int $sort): string
    {
        return (string) DB::selectOne('select audit_item_save(?, ?, ?, ?, ?::integer) as r', [$id ?? CreateWorkspace::newId(), $tenantId, $userId, $label, $sort])->r;
    }

    public function setItemActive(string $tenantId, string $id, bool $active): string
    {
        return (string) DB::selectOne('select audit_item_active(?, ?, ?::boolean) as r', [$id, $tenantId, $active ? 'true' : 'false'])->r;
    }

    public function tick(string $tenantId, string $userId, string $id, string $period, string $on, ?string $note): string
    {
        return (string) DB::selectOne('select audit_item_done(?, ?, ?, ?, ?::date, ?) as r', [$id, $tenantId, $userId, $period, $on, $note])->r;
    }

    public function untick(string $tenantId, string $id, string $period): string
    {
        return (string) DB::selectOne('select audit_item_undo(?, ?, ?) as r', [$id, $tenantId, $period])->r;
    }

    public function seedItems(string $tenantId, string $userId): string
    {
        return (string) DB::selectOne('select audit_item_seed(?, ?) as r', [$tenantId, $userId])->r;
    }
}
