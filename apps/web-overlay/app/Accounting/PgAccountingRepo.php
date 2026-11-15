<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgAccountingRepo implements AccountingRepo
{
    public function settings(): array
    {
        $r = DB::selectOne('select locked_through, seeded_at from acct_settings limit 1');

        return ['locked_through' => $r?->locked_through, 'seeded' => $r !== null && $r->seeded_at !== null];
    }

    public function seed(string $tenantId): int
    {
        $ids = [];
        for ($i = 0; $i < 60; $i++) {
            $ids[] = CreateWorkspace::newId();
        }

        return (int) DB::selectOne('select acct_seed(?, ?::text[]) as n', [$tenantId, '{'.implode(',', $ids).'}'])->n;
    }

    public function accounts(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'code' => (string) $r->code, 'name' => (string) $r->name, 'name_bn' => $r->name_bn, 'type' => (string) $r->type,
            'parent_id' => $r->parent_id === null ? null : trim((string) $r->parent_id), 'postable' => (bool) $r->postable, 'system_key' => $r->system_key, 'active' => (bool) $r->active],
            DB::select('select id, code, name, name_bn, type, parent_id, postable, system_key, active from accounts order by code'));
    }

    public function addAccount(string $tenantId, string $code, string $name, ?string $nameBn, string $type, ?string $parentId): string
    {
        return (string) DB::selectOne('select acct_account_add(?, ?, ?, ?, ?, ?, ?) as r', [CreateWorkspace::newId(), $tenantId, $code, $name, $nameBn, $type, $parentId])->r;
    }

    public function setActive(string $accountId, bool $active): void
    {
        DB::update('update accounts set active = ?::boolean where id = ?', [$active ? 'true' : 'false', $accountId]);
    }

    public function post(string $tenantId, string $userId, string $date, string $memo, array $lines): array
    {
        $r = DB::selectOne('select result, entry_id, number from journal_post(?, ?, ?, ?::date, ?, ?, ?, ?::jsonb)',
            [CreateWorkspace::newId(), $tenantId, $userId, $date, $memo, 'manual', null, json_encode($lines, JSON_UNESCAPED_UNICODE)]);

        return ['result' => (string) $r->result, 'entry_id' => $r->entry_id === null ? null : trim((string) $r->entry_id), 'number' => $r->number === null ? null : (int) $r->number];
    }

    public function reverse(string $entryId, string $userId, string $date, ?string $memo): string
    {
        return (string) DB::selectOne('select journal_reverse(?, ?, ?, ?::date, ?) as r', [CreateWorkspace::newId(), $entryId, $userId, $date, $memo])->r;
    }

    public function entries(int $limit = 100): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r,
            DB::select('select e.id, e.number, e.entry_date, e.memo, e.source, e.reverses_id, t.total from journal_entries e join lateral (select sum(debit_minor) as total from journal_lines where entry_id = e.id) t on true order by e.entry_date desc, e.number desc limit ?', [min(300, max(1, $limit))]));
    }

    public function entry(string $id): ?array
    {
        $e = DB::selectOne('select id, number, entry_date, memo, source, source_ref, reverses_id, created_by, created_at from journal_entries where id = ?', [$id]);
        if ($e === null) {
            return null;
        }
        $lines = array_map(fn ($r) => (array) $r, DB::select('select l.account_id, a.code, a.name, a.name_bn, l.debit_minor, l.credit_minor, l.memo from journal_lines l join accounts a on a.id = l.account_id and a.tenant_id = l.tenant_id where l.entry_id = ? order by l.seq', [$id]));
        $rev = DB::selectOne('select id, number from journal_entries where reverses_id = ?', [$id]);
        $e = (array) $e;
        $e['id'] = trim((string) $e['id']);

        return ['entry' => $e, 'lines' => $lines, 'reversed_by' => $rev === null ? null : ['id' => trim((string) $rev->id), 'number' => (int) $rev->number]];
    }

    public function trialBalance(string $asof): array
    {
        return array_map(fn ($r) => ['account_id' => trim((string) $r->account_id)] + (array) $r, DB::select('select account_id, code, name, name_bn, type, debit_minor, credit_minor from acct_trial_balance(?::date)', [$asof]));
    }

    public function ledger(string $accountId, string $from, string $to): array
    {
        return array_map(fn ($r) => (array) $r, DB::select('select entry_id, number, entry_date, memo, line_memo, debit_minor, credit_minor, balance_minor from acct_ledger(?, ?::date, ?::date)', [$accountId, $from, $to]));
    }

    public function lock(string $tenantId, string $userId, string $through): string
    {
        return (string) DB::selectOne('select acct_lock(?, ?::date, ?) as r', [$tenantId, $through, $userId])->r;
    }

    public function unlock(string $tenantId, string $userId, ?string $to, string $reason): string
    {
        return (string) DB::selectOne('select acct_unlock(?, ?::date, ?, ?) as r', [$tenantId, $to, $userId, $reason])->r;
    }

    public function lockLog(int $limit = 20): array
    {
        return array_map(fn ($r) => (array) $r, DB::select('select action, from_date, to_date, reason, created_at from acct_lock_log order by seq desc limit ?', [min(100, max(1, $limit))]));
    }
}
