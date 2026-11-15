<?php

declare(strict_types=1);

namespace App\Analyst;

use Illuminate\Support\Facades\DB;

final class PgAnalystRepo implements AnalystRepo
{
    public function monthly(string $from, string $to): array
    {
        return array_map(fn ($r) => ['ym' => (string) $r->ym, 'type' => (string) $r->type, 'account_id' => trim((string) $r->account_id), 'name' => (string) $r->name, 'amount_minor' => (int) $r->amount],
            DB::select("select to_char(e.entry_date, 'YYYY-MM') as ym, a.type, a.id as account_id, coalesce(nullif(a.name_bn, ''), a.name) as name,
                sum(case when a.type = 'income' then l.credit_minor - l.debit_minor else l.debit_minor - l.credit_minor end)::bigint as amount
                from journal_lines l join journal_entries e on e.id = l.entry_id and e.tenant_id = l.tenant_id join accounts a on a.id = l.account_id and a.tenant_id = l.tenant_id
                where a.type in ('income', 'expense') and e.entry_date between ?::date and ?::date
                group by 1, 2, 3, 4 order by 1, 2, 4", [$from, $to]));
    }

    public function cashMonthly(string $from, string $to): array
    {
        return array_map(fn ($r) => ['ym' => (string) $r->ym, 'amount_minor' => (int) $r->amount],
            DB::select("select to_char(e.entry_date, 'YYYY-MM') as ym, sum(l.debit_minor - l.credit_minor)::bigint as amount
                from journal_lines l join journal_entries e on e.id = l.entry_id and e.tenant_id = l.tenant_id join accounts a on a.id = l.account_id and a.tenant_id = l.tenant_id
                where a.cash_account and e.entry_date between ?::date and ?::date group by 1 order by 1", [$from, $to]));
    }

    public function cashBalance(string $asof): int
    {
        return (int) DB::selectOne('select coalesce(sum(l.debit_minor - l.credit_minor), 0)::bigint as b
            from journal_lines l join journal_entries e on e.id = l.entry_id and e.tenant_id = l.tenant_id join accounts a on a.id = l.account_id and a.tenant_id = l.tenant_id
            where a.cash_account and e.entry_date <= ?::date', [$asof])->b;
    }

    public function openItems(): array
    {
        $r = DB::selectOne("select coalesce(sum(l.debit_minor - l.credit_minor) filter (where a.system_key = 'receivable'), 0)::bigint as recv,
            coalesce(sum(l.credit_minor - l.debit_minor) filter (where a.system_key = 'payable'), 0)::bigint as pay
            from journal_lines l join accounts a on a.id = l.account_id and a.tenant_id = l.tenant_id where a.system_key in ('receivable', 'payable')");

        return ['receivable' => (int) $r->recv, 'payable' => (int) $r->pay];
    }

    public function saveReport(string $tenantId, string $userId, string $id, string $month, array $facts, array $forecast, string $narrative, string $source, ?string $note, ?string $model, int $in, int $out, string $credits): string
    {
        return (string) DB::selectOne('select analyst_report_save(?, ?, ?, ?, ?::jsonb, ?::jsonb, ?, ?, ?, ?, ?::integer, ?::integer, ?::numeric) as r', [$id, $tenantId, $userId, $month,
            json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), json_encode($forecast, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $narrative, $source, $note, $model, $in, $out, $credits])->r;
    }

    public function report(string $id): ?array
    {
        $r = DB::selectOne('select id, month_to, facts, forecast, narrative, source, note, model, credits_charged, created_at from analyst_reports where id = ?', [$id]);

        return $r === null ? null : ['id' => trim((string) $r->id), 'month_to' => trim((string) $r->month_to), 'facts' => (array) json_decode((string) $r->facts, true), 'forecast' => (array) json_decode((string) $r->forecast, true),
            'narrative' => (string) $r->narrative, 'source' => (string) $r->source, 'note' => $r->note, 'model' => $r->model, 'credits_charged' => (string) $r->credits_charged, 'created_at' => (string) $r->created_at];
    }

    public function recent(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'month_to' => trim((string) $r->month_to), 'source' => (string) $r->source, 'created_at' => (string) $r->created_at],
            DB::select('select id, month_to, source, created_at from analyst_reports order by created_at desc limit ?', [min(100, max(1, $limit))]));
    }
}
