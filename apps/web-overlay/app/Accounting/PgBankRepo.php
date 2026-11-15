<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgBankRepo implements BankRepo
{
    public function accounts(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'code' => (string) $r->code, 'name' => (string) $r->name, 'name_bn' => $r->name_bn],
            DB::select('select id, code, name, name_bn from accounts where cash_account and active order by code'));
    }

    public function import(string $tenantId, string $userId, string $accountId, string $filename, array $rows): array
    {
        $r = DB::selectOne('select result, rows_new, rows_dup from bank_import(?, ?, ?, ?, ?, ?::jsonb)', [CreateWorkspace::newId(), $tenantId, $userId, $accountId, $filename, json_encode($rows, JSON_UNESCAPED_UNICODE)]);

        return ['result' => (string) $r->result, 'rows_new' => (int) $r->rows_new, 'rows_dup' => (int) $r->rows_dup];
    }

    public function lines(string $accountId, string $status, int $limit = 200): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r,
            DB::select("select b.id, b.txn_date, b.description, b.reference, b.amount_minor, b.balance_minor, b.status, b.journal_line_seq, e.id as entry_id, e.number as entry_number, e.memo as entry_memo from bank_lines b left join journal_lines l on l.seq = b.journal_line_seq left join journal_entries e on e.id = l.entry_id and e.tenant_id = l.tenant_id where b.account_id = ? and (? = 'all' or b.status = ?) order by b.txn_date desc, b.id desc limit ?",
                [$accountId, $status, $status, min(500, max(1, $limit))]));
    }

    public function suggestions(string $accountId, int $window = 3): array
    {
        return array_map(fn ($r) => ['line_id' => trim((string) $r->line_id), 'entry_id' => trim((string) $r->entry_id)] + (array) $r,
            DB::select('select line_id, journal_line_seq, entry_id, entry_number, entry_date, memo, day_diff from bank_suggestions(?, ?)', [$accountId, $window]));
    }

    public function match(string $lineId, int $journalLineSeq, string $userId): string
    {
        return (string) DB::selectOne('select bank_match(?, ?::bigint, ?) as r', [$lineId, $journalLineSeq, $userId])->r;
    }

    public function unmatch(string $lineId): string
    {
        return (string) DB::selectOne('select bank_unmatch(?) as r', [$lineId])->r;
    }

    public function ignore(string $lineId, bool $on): string
    {
        return (string) DB::selectOne('select bank_ignore(?, ?::boolean) as r', [$lineId, $on ? 'true' : 'false'])->r;
    }

    public function createEntry(string $lineId, string $userId, string $counterAccountId, ?string $memo): string
    {
        return (string) DB::selectOne('select bank_create_entry(?, ?, ?, ?, ?) as r', [$lineId, CreateWorkspace::newId(), $userId, $counterAccountId, $memo])->r;
    }

    public function autoMatch(string $accountId, string $userId, int $window = 3): int
    {
        return (int) DB::selectOne('select bank_auto_match(?, ?, ?::integer) as n', [$accountId, $userId, $window])->n;
    }

    public function reconciliation(string $accountId, string $asof): array
    {
        return (array) DB::selectOne('select book_minor, statement_minor, since, matched_count, open_lines, open_lines_minor, ignored_count, open_book_lines, open_book_minor from bank_reconciliation(?, ?::date)', [$accountId, $asof]);
    }

    public function imports(string $accountId, int $limit = 10): array
    {
        return array_map(fn ($r) => (array) $r, DB::select('select filename, rows_total, rows_new, rows_dup, created_at from bank_imports where account_id = ? order by created_at desc limit ?', [$accountId, min(50, max(1, $limit))]));
    }
}
