<?php

declare(strict_types=1);

namespace App\Payroll;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgPayrollRepo implements PayrollRepo
{
    private static function emp(object $r): array
    {
        return ['id' => trim((string) $r->id), 'code' => (string) $r->code, 'name' => (string) $r->name, 'designation' => $r->designation, 'join_date' => (string) $r->join_date,
            'end_date' => $r->end_date === null ? null : (string) $r->end_date, 'active' => (bool) $r->active] + (isset($r->gross) ? ['gross_minor' => (int) $r->gross] : []);
    }

    public function employees(): array
    {
        return array_map(fn ($r) => self::emp($r), DB::select("select e.id, e.code, e.name, e.designation, e.join_date, e.end_date, e.active,
            coalesce(sum(c.amount_minor) filter (where c.kind = 'earning'), 0) as gross
            from payroll_employees e left join payroll_components c on c.employee_id = e.id and c.effective_from <= (now() at time zone 'Asia/Dhaka')::date and (c.effective_to is null or c.effective_to >= (now() at time zone 'Asia/Dhaka')::date)
            group by e.id order by e.active desc, e.code"));
    }

    public function employee(string $id): ?array
    {
        $r = DB::selectOne('select id, code, name, designation, join_date, end_date, active from payroll_employees where id = ?', [$id]);

        return $r === null ? null : self::emp($r);
    }

    public function saveEmployee(string $tenantId, string $userId, ?string $id, string $code, string $name, ?string $designation, string $join, ?string $end): string
    {
        return (string) DB::selectOne('select payroll_employee_save(?, ?, ?, ?, ?, ?, ?::date, ?::date) as r', [$id ?? CreateWorkspace::newId(), $tenantId, $userId, $code, $name, $designation, $join, $end])->r;
    }

    public function setEmployeeActive(string $tenantId, string $id, bool $active): string
    {
        return (string) DB::selectOne('select payroll_employee_active(?, ?, ?::boolean) as r', [$id, $tenantId, $active ? 'true' : 'false'])->r;
    }

    public function components(string $employeeId): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'kind' => (string) $r->kind, 'is_tax' => (bool) $r->is_tax, 'label' => (string) $r->label, 'amount_minor' => (int) $r->amount_minor,
            'effective_from' => (string) $r->effective_from, 'effective_to' => $r->effective_to === null ? null : (string) $r->effective_to],
            DB::select('select id, kind, is_tax, label, amount_minor, effective_from, effective_to from payroll_components where employee_id = ? order by (effective_to is not null), kind desc, label, effective_from desc', [$employeeId]));
    }

    public function saveComponent(string $tenantId, string $userId, string $employeeId, string $kind, bool $tax, string $label, int $amountMinor, string $from): string
    {
        return (string) DB::selectOne('select payroll_component_save(?, ?, ?, ?, ?, ?::boolean, ?, ?::bigint, ?::date) as r', [CreateWorkspace::newId(), $tenantId, $userId, $employeeId, $kind, $tax ? 'true' : 'false', $label, $amountMinor, $from])->r;
    }

    public function endComponent(string $tenantId, string $id, string $to): string
    {
        return (string) DB::selectOne('select payroll_component_end(?, ?, ?::date) as r', [$id, $tenantId, $to])->r;
    }

    public function runs(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'period' => (string) $r->period, 'status' => (string) $r->status, 'gross_minor' => (int) $r->gross_minor, 'deduction_minor' => (int) $r->deduction_minor,
            'tax_minor' => (int) $r->tax_minor, 'net_minor' => (int) $r->net_minor, 'staff' => (int) $r->staff],
            DB::select('select r.id, r.period, r.status, r.gross_minor, r.deduction_minor, r.tax_minor, r.net_minor, (select count(*) from payroll_lines l where l.run_id = r.id) as staff from payroll_runs r order by r.period desc, r.created_at desc limit ?', [$limit]));
    }

    public function run(string $id): ?array
    {
        $r = DB::selectOne('select id, period, status, gross_minor, deduction_minor, tax_minor, net_minor, post_date, pay_date, post_entry_id, pay_account_id from payroll_runs where id = ?', [$id]);
        if ($r === null) {
            return null;
        }
        $lines = array_map(fn ($l) => ['employee_id' => trim((string) $l->employee_id), 'code' => (string) $l->code, 'name' => (string) $l->name, 'items' => (array) json_decode((string) $l->items, true),
            'gross_minor' => (int) $l->gross_minor, 'deduction_minor' => (int) $l->deduction_minor, 'tax_minor' => (int) $l->tax_minor, 'net_minor' => (int) $l->net_minor],
            DB::select('select l.employee_id, e.code, e.name, l.items, l.gross_minor, l.deduction_minor, l.tax_minor, l.net_minor from payroll_lines l join payroll_employees e on e.id = l.employee_id and e.tenant_id = l.tenant_id where l.run_id = ? order by e.code', [$id]));

        return ['run' => ['id' => trim((string) $r->id), 'period' => (string) $r->period, 'status' => (string) $r->status, 'gross_minor' => (int) $r->gross_minor, 'deduction_minor' => (int) $r->deduction_minor, 'tax_minor' => (int) $r->tax_minor,
            'net_minor' => (int) $r->net_minor, 'post_date' => $r->post_date, 'pay_date' => $r->pay_date, 'post_entry_id' => $r->post_entry_id === null ? null : trim((string) $r->post_entry_id)], 'lines' => $lines];
    }

    public function createRun(string $tenantId, string $userId, string $id, string $period): string
    {
        return (string) DB::selectOne('select payroll_run_create(?, ?, ?, ?) as r', [$id, $tenantId, $userId, $period])->r;
    }

    public function adjustAdd(string $tenantId, string $runId, string $employeeId, string $kind, string $label, int $amountMinor, bool $tax): string
    {
        return (string) DB::selectOne('select payroll_adjust_add(?, ?, ?, ?, ?, ?::bigint, ?::boolean) as r', [$runId, $tenantId, $employeeId, $kind, $label, $amountMinor, $tax ? 'true' : 'false'])->r;
    }

    public function adjustRemove(string $tenantId, string $runId, string $employeeId, string $itemId): string
    {
        return (string) DB::selectOne('select payroll_adjust_remove(?, ?, ?, ?) as r', [$runId, $tenantId, $employeeId, $itemId])->r;
    }

    public function post(string $tenantId, string $userId, string $runId, string $date): string
    {
        return (string) DB::selectOne('select payroll_run_post(?, ?, ?, ?, ?::date) as r', [$runId, $tenantId, $userId, CreateWorkspace::newId(), $date])->r;
    }

    public function pay(string $tenantId, string $userId, string $runId, string $date, string $accountId): string
    {
        return (string) DB::selectOne('select payroll_run_pay(?, ?, ?, ?, ?::date, ?) as r', [$runId, $tenantId, $userId, CreateWorkspace::newId(), $date, $accountId])->r;
    }

    public function void(string $tenantId, string $userId, string $runId, string $date): string
    {
        return (string) DB::selectOne('select payroll_run_void(?, ?, ?, ?, ?::date) as r', [$runId, $tenantId, $userId, CreateWorkspace::newId(), $date])->r;
    }

    public function cashAccounts(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r, DB::select('select id, code, name, name_bn from accounts where cash_account and active order by code'));
    }

    public function payDetails(): array
    {
        $out = [];
        foreach (DB::select('select employee_id, method, bank_name, branch, routing_no, account_no, wallet_no from payroll_pay_details') as $r) {
            $out[trim((string) $r->employee_id)] = ['method' => (string) $r->method, 'bank_name' => $r->bank_name, 'branch' => $r->branch, 'routing_no' => $r->routing_no === null ? null : trim((string) $r->routing_no),
                'account_no' => $r->account_no, 'wallet_no' => $r->wallet_no === null ? null : trim((string) $r->wallet_no)];
        }

        return $out;
    }

    public function savePayDetails(string $tenantId, string $userId, string $employeeId, string $method, ?string $bank, ?string $branch, ?string $routing, ?string $account, ?string $wallet): string
    {
        return (string) DB::selectOne('select payroll_pay_details_save(?, ?, ?, ?, ?, ?, ?, ?, ?) as r', [$tenantId, $userId, $employeeId, $method, $bank, $branch, $routing, $account, $wallet])->r;
    }

    public function tenantName(string $tenantId): string
    {
        return (string) (DB::selectOne('select name from tenants where id = ?', [$tenantId])->name ?? '');
    }
}
