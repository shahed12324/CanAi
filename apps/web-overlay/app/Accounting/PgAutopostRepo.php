<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgAutopostRepo implements AutopostRepo
{
    public function enabled(): bool
    {
        return (bool) (DB::selectOne('select enabled from autopost_settings limit 1')->enabled ?? false);
    }

    public function setEnabled(string $tenantId, string $userId, bool $on): string
    {
        return (string) DB::selectOne('select autopost_set(?, ?, ?::boolean) as r', [$tenantId, $userId, $on ? 'true' : 'false'])->r;
    }

    public function counts(): array
    {
        $r = DB::selectOne("select count(*) filter (where status = 'pending') as pending, count(*) filter (where status = 'failed') as failed, count(*) filter (where status = 'posted') as posted, count(*) filter (where status = 'skipped') as skipped from autopost_queue");

        return ['pending' => (int) $r->pending, 'failed' => (int) $r->failed, 'posted' => (int) $r->posted, 'skipped' => (int) $r->skipped];
    }

    public function queue(int $limit): array
    {
        return array_map(fn ($r) => (array) $r,
            DB::select("select q.seq, q.kind, q.status, q.attempts, q.last_error, q.note, q.courier, q.amount_minor, q.created_at, q.done_at, o.number as order_number, o.customer_name, o.total_minor as order_total_minor, e.number as entry_number from autopost_queue q join store_orders o on o.id = q.order_id and o.tenant_id = q.tenant_id left join journal_entries e on e.id = q.entry_id and e.tenant_id = q.tenant_id order by (q.status in ('pending', 'failed')) desc, q.seq desc limit ?", [$limit]));
    }

    public function run(string $tenantId, string $userId, int $limit): array
    {
        $r = DB::selectOne('select posted, failed, skipped from autopost_run(?, ?, ?::integer)', [$tenantId, $userId, $limit]);

        return ['posted' => (int) $r->posted, 'failed' => (int) $r->failed, 'skipped' => (int) $r->skipped];
    }

    public function skip(int $seq, string $userId, string $reason): string
    {
        return (string) DB::selectOne('select autopost_skip(?::bigint, ?, ?) as r', [$seq, $userId, $reason])->r;
    }

    public function receivable(): array
    {
        return array_map(fn ($r) => ['courier' => (string) $r->courier, 'orders' => (int) $r->orders, 'delivered_minor' => (int) $r->delivered_minor, 'returned_minor' => (int) $r->returned_minor, 'settled_minor' => (int) $r->settled_minor, 'outstanding_minor' => (int) $r->outstanding_minor],
            DB::select('select courier, orders, delivered_minor, returned_minor, settled_minor, outstanding_minor from courier_receivable_report()'));
    }

    public function settlements(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r,
            DB::select('select s.id, s.courier, s.settle_date, s.gross_minor, s.fee_minor, s.note, s.voided_at, coalesce(a.name_bn, a.name) as account_name, e.number as entry_number from courier_settlements s join accounts a on a.id = s.account_id and a.tenant_id = s.tenant_id join journal_entries e on e.id = s.entry_id and e.tenant_id = s.tenant_id order by s.settle_date desc, s.created_at desc limit ?', [$limit]));
    }

    public function settle(string $tenantId, string $userId, string $date, string $courier, int $gross, int $fee, string $accountId, ?string $note): string
    {
        return (string) DB::selectOne('select courier_settle(?, ?, ?, ?, ?::date, ?, ?::bigint, ?::bigint, ?, ?) as r', [CreateWorkspace::newId(), CreateWorkspace::newId(), $tenantId, $userId, $date, $courier, $gross, $fee, $accountId, $note])->r;
    }

    public function voidSettlement(string $id, string $userId, string $date): string
    {
        return (string) DB::selectOne('select courier_settle_void(?, ?, ?, ?::date) as r', [CreateWorkspace::newId(), $id, $userId, $date])->r;
    }

    public function cashAccounts(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'code' => (string) $r->code, 'name' => (string) $r->name, 'name_bn' => $r->name_bn],
            DB::select('select id, code, name, name_bn from accounts where cash_account and active order by code'));
    }
}
