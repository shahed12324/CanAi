<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgInventoryRepo implements InventoryRepo
{
    public function valuation(string $asof): array
    {
        return array_map(fn ($r) => ['variant_id' => trim((string) $r->variant_id)] + (array) $r,
            DB::select('select variant_id, title, sku, options, qty, unit_cost_minor, value_minor from inv_valuation(?::date)', [$asof]));
    }

    public function bookBalance(string $asof): int
    {
        return (int) DB::selectOne("select coalesce(sum(l.debit_minor - l.credit_minor), 0) as b from journal_lines l join journal_entries e on e.id = l.entry_id and e.tenant_id = l.tenant_id join accounts a on a.id = l.account_id and a.tenant_id = l.tenant_id where a.system_key = 'inventory' and e.entry_date <= ?::date", [$asof])->b;
    }

    public function setCost(string $variantId, int $costMinor, string $date, string $userId): string
    {
        return (string) DB::selectOne('select inv_cost_set(?, ?::bigint, ?::date, ?) as r', [$variantId, $costMinor, $date, $userId])->r;
    }

    public function costHistory(string $variantId, int $limit = 10): array
    {
        return array_map(fn ($r) => ['effective_date' => (string) $r->effective_date, 'unit_cost_minor' => (int) $r->unit_cost_minor],
            DB::select('select effective_date, unit_cost_minor from inv_costs where variant_id = ? order by effective_date desc, seq desc limit ?', [$variantId, min(50, max(1, $limit))]));
    }

    public function revalue(string $tenantId, string $userId, string $date, string $counterAccountId): array
    {
        $r = DB::selectOne('select result, value_minor, book_minor, diff_minor from inv_revalue(?, ?, ?, ?::date, ?)', [CreateWorkspace::newId(), $tenantId, $userId, $date, $counterAccountId]);

        return ['result' => (string) $r->result, 'value_minor' => (int) $r->value_minor, 'book_minor' => (int) $r->book_minor, 'diff_minor' => (int) $r->diff_minor];
    }

    public function counterAccounts(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'code' => (string) $r->code, 'name' => (string) $r->name, 'name_bn' => $r->name_bn, 'system_key' => $r->system_key],
            DB::select("select id, code, name, name_bn, system_key from accounts where type in ('expense', 'income') and postable and active order by code"));
    }
}
