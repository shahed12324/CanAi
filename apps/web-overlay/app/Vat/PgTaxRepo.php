<?php

declare(strict_types=1);

namespace App\Vat;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgTaxRepo implements TaxRepo
{
    public function rules(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'kind' => (string) $r->kind, 'code' => (string) $r->code, 'label' => (string) $r->label, 'rate_bp' => (int) $r->rate_bp, 'effective_from' => (string) $r->effective_from, 'effective_to' => $r->effective_to === null ? null : (string) $r->effective_to],
            DB::select('select id, kind, code, label, rate_bp, effective_from, effective_to from tax_rules order by kind, code, effective_from desc'));
    }

    public function saveRule(string $tenantId, string $userId, string $kind, string $code, string $label, int $bp, string $from): string
    {
        return (string) DB::selectOne('select tax_rule_save(?, ?, ?, ?, ?, ?, ?::integer, ?::date) as r', [CreateWorkspace::newId(), $tenantId, $userId, $kind, $code, $label, $bp, $from])->r;
    }

    public function settle(string $userId, string $docId, string $date, int $grossMinor, string $accountId, ?string $note, array $items): string
    {
        return (string) DB::selectOne('select tax_settle(?, ?, ?, ?, ?::date, ?::bigint, ?, ?, ?::jsonb) as r',
            [CreateWorkspace::newId(), CreateWorkspace::newId(), $docId, $userId, $date, $grossMinor, $accountId, $note, json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)])->r;
    }

    public function setCert(string $withholdingId, string $userId, ?string $cert): string
    {
        return (string) DB::selectOne('select tax_cert_set(?, ?, ?) as r', [$withholdingId, $userId, $cert])->r;
    }

    public function deposit(string $tenantId, string $userId, string $kind, string $date, string $upto, string $accountId, ?string $challan): array
    {
        $r = DB::selectOne('select result, amount_minor from tax_deposit(?, ?, ?, ?, ?, ?::date, ?::date, ?, ?)', [CreateWorkspace::newId(), CreateWorkspace::newId(), $tenantId, $userId, $kind, $date, $upto, $accountId, $challan]);

        return ['result' => (string) $r->result, 'amount_minor' => (int) $r->amount_minor];
    }

    public function advancePay(string $tenantId, string $userId, string $date, int $amountMinor, string $accountId, ?string $ref, ?string $note): string
    {
        return (string) DB::selectOne("select tax_advance_pay(?, ?, ?, ?, 'ait', ?::date, ?::bigint, ?, ?, ?) as r", [CreateWorkspace::newId(), CreateWorkspace::newId(), $tenantId, $userId, $date, $amountMinor, $accountId, $ref, $note])->r;
    }

    public function advanceVoid(string $advanceId, string $userId, string $date): string
    {
        return (string) DB::selectOne('select tax_advance_void(?, ?, ?, ?::date) as r', [CreateWorkspace::newId(), $advanceId, $userId, $date])->r;
    }

    public function summary(): array
    {
        $out = ['owed' => ['vds' => 0, 'tds' => 0], 'deposited' => ['vds' => 0, 'tds' => 0], 'suffered' => ['vds' => 0, 'tds' => 0], 'advance_minor' => 0];
        foreach (DB::select("select w.direction, w.kind, (w.deposit_id is not null) as dep, coalesce(sum(w.amount_minor), 0) as s from tax_withholdings w join acct_payments p on p.id = w.payment_id and p.tenant_id = w.tenant_id where p.voided_at is null group by 1, 2, 3") as $r) {
            if ($r->direction === 'suffered') {
                $out['suffered'][$r->kind] += (int) $r->s;
            } elseif ($r->dep) {
                $out['deposited'][$r->kind] += (int) $r->s;
            } else {
                $out['owed'][$r->kind] += (int) $r->s;
            }
        }
        $out['advance_minor'] = (int) (DB::selectOne('select coalesce(sum(amount_minor), 0) as s from tax_advances where voided_at is null')->s ?? 0);

        return $out;
    }

    public function withholdings(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r,
            DB::select('select w.id, w.direction, w.kind, w.rule_code, w.base_minor, w.rate_bp, w.amount_minor, w.tax_date, w.cert_no, w.deposit_id, d.number as doc_number, d.id as doc_id, pa.name as party, (p.voided_at is not null) as cancelled from tax_withholdings w join acct_docs d on d.id = w.doc_id and d.tenant_id = w.tenant_id join acct_payments p on p.id = w.payment_id and p.tenant_id = w.tenant_id left join acct_parties pa on pa.id = w.party_id and pa.tenant_id = w.tenant_id order by w.tax_date desc, w.created_at desc limit ?', [min(300, max(1, $limit))]));
    }

    public function deposits(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r,
            DB::select('select t.id, t.kind, t.deposit_date, t.amount_minor, t.challan_ref, a.name as account from tax_deposits t join accounts a on a.id = t.account_id and a.tenant_id = t.tenant_id order by t.deposit_date desc, t.created_at desc limit ?', [min(100, max(1, $limit))]));
    }

    public function advances(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r,
            DB::select('select t.id, t.tax_date, t.amount_minor, t.reference, t.note, t.voided_at, a.name as account from tax_advances t join accounts a on a.id = t.account_id and a.tenant_id = t.tenant_id order by t.tax_date desc, t.created_at desc limit ?', [min(100, max(1, $limit))]));
    }

    public function cashAccounts(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r, DB::select('select id, code, name, name_bn from accounts where cash_account and active order by code'));
    }
}
