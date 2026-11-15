<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgAssetRepo implements AssetRepo
{

    public function assets(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r,
            DB::select("select f.id, f.name, f.purchase_date, f.cost_minor, f.salvage_minor, f.life_months, f.status, f.disposed_date, f.proceeds_minor, f.disposal_entry_id, a.code as account_code, coalesce(a.name_bn, a.name) as account_name, coalesce((select sum(l.amount_minor) from fa_dep_lines l where l.asset_id = f.id), 0) as accumulated_minor from fixed_assets f join accounts a on a.id = f.asset_account_id and a.tenant_id = f.tenant_id order by f.status, f.purchase_date desc, f.id"));
    }

    public function asset(string $id): ?array
    {
        $r = DB::selectOne("select f.id, f.name, f.purchase_date, f.cost_minor, f.salvage_minor, f.life_months, f.status, f.disposed_date, f.proceeds_minor, f.disposal_entry_id, a.code as account_code, coalesce(a.name_bn, a.name) as account_name, coalesce((select sum(l.amount_minor) from fa_dep_lines l where l.asset_id = f.id), 0) as accumulated_minor from fixed_assets f join accounts a on a.id = f.asset_account_id and a.tenant_id = f.tenant_id where f.id = ?", [$id]);

        return $r === null ? null : ['id' => trim((string) $r->id)] + (array) $r;
    }

    public function schedule(string $id): array
    {
        return array_map(fn ($r) => ['period' => (string) $r->period, 'amount_minor' => (int) $r->amount_minor, 'done' => (bool) $r->done],
            DB::select("select to_char(date_trunc('month', f.purchase_date::timestamp) + (k * interval '1 month'), 'YYYY-MM') as period, fa_month_amount(f.cost_minor, f.salvage_minor, f.life_months, k) as amount_minor, exists (select 1 from fa_dep_lines l where l.asset_id = f.id and l.period = (date_trunc('month', f.purchase_date::timestamp) + (k * interval '1 month'))::date) as done from fixed_assets f cross join generate_series(0, f.life_months - 1) as k where f.id = ? and (f.status = 'active' or date_trunc('month', f.purchase_date::timestamp) + (k * interval '1 month') < date_trunc('month', f.disposed_date::timestamp)) order by k", [$id]));
    }

    public function register(string $tenantId, string $userId, string $name, string $assetAccountId, string $date, int $cost, int $salvage, int $lifeMonths): string
    {
        return (string) DB::selectOne('select fa_register(?, ?, ?, ?, ?, ?::date, ?::bigint, ?::bigint, ?::integer) as r', [CreateWorkspace::newId(), $tenantId, $userId, $name, $assetAccountId, $date, $cost, $salvage, $lifeMonths])->r;
    }

    public function pending(string $asof): array
    {
        return array_map(fn ($r) => ['period' => (string) $r->period, 'assets' => (int) $r->assets, 'total_minor' => (int) $r->total_minor],
            DB::select("select to_char(period, 'YYYY-MM') as period, count(*) as assets, sum(amount_minor) as total_minor from fa_pending(?::date) where amount_minor > 0 group by period order by period", [$asof]));
    }

    public function run(string $tenantId, string $userId, string $month): array
    {
        $r = DB::selectOne('select result, assets, total_minor from fa_run(?, ?, ?, ?::date)', [CreateWorkspace::newId(), $tenantId, $userId, $month]);

        return ['result' => (string) $r->result, 'assets' => (int) $r->assets, 'total_minor' => (int) $r->total_minor];
    }

    public function dispose(string $assetId, string $userId, string $date, int $proceedsMinor, ?string $payAccountId): string
    {
        return (string) DB::selectOne('select fa_dispose(?, ?, ?, ?::date, ?::bigint, ?) as r', [CreateWorkspace::newId(), $assetId, $userId, $date, $proceedsMinor, $payAccountId])->r;
    }

    public function assetAccounts(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'code' => (string) $r->code, 'name' => (string) $r->name, 'name_bn' => $r->name_bn],
            DB::select("select id, code, name, name_bn from accounts where type = 'asset' and postable and active and not cash_account and coalesce(system_key, '') not in ('receivable', 'courier_receivable', 'payable', 'inventory', 'accum_depreciation', 'vat_input', 'prepaid') order by code"));
    }

    public function cashAccounts(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'code' => (string) $r->code, 'name' => (string) $r->name, 'name_bn' => $r->name_bn],
            DB::select('select id, code, name, name_bn from accounts where cash_account and active order by code'));
    }
}
