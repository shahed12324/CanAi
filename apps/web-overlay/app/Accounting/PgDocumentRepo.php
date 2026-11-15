<?php

declare(strict_types=1);

namespace App\Accounting;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgDocumentRepo implements DocumentRepo
{
    public function parties(?string $kind = null): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'kind' => (string) $r->kind, 'name' => (string) $r->name, 'phone' => $r->phone, 'note' => $r->note, 'active' => (bool) $r->active],
            DB::select("select id, kind, name, phone, note, active from acct_parties where (? = 'all' or kind = ?) order by active desc, name", [$kind ?? 'all', $kind ?? 'all']));
    }

    public function addParty(string $tenantId, string $kind, string $name, ?string $phone, ?string $note): string
    {
        return (string) DB::selectOne('select acct_party_add(?, ?, ?, ?, ?, ?) as r', [CreateWorkspace::newId(), $tenantId, $kind, $name, $phone, $note])->r;
    }

    public function setPartyActive(string $partyId, bool $active): void
    {
        DB::update('update acct_parties set active = ?::boolean where id = ?', [$active ? 'true' : 'false', $partyId]);
    }

    public function create(string $tenantId, string $userId, string $kind, ?string $partyId, string $date, ?string $due, ?string $reference, array $lines, int $vatMinor, ?string $payAccountId): array
    {
        $r = DB::selectOne('select result, doc_id, number, total_minor from acct_doc_create(?, ?, ?, ?, ?, ?, ?::date, ?::date, ?, ?::jsonb, ?::bigint, ?)',
            [CreateWorkspace::newId(), CreateWorkspace::newId(), $tenantId, $userId, $kind, $partyId, $date, $due, $reference, json_encode($lines, JSON_UNESCAPED_UNICODE), $vatMinor, $payAccountId]);

        return ['result' => (string) $r->result, 'doc_id' => $r->doc_id === null ? null : trim((string) $r->doc_id), 'number' => $r->number, 'total_minor' => $r->total_minor === null ? null : (int) $r->total_minor];
    }

    public function docs(string $kind, int $limit = 100): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r,
            DB::select('select d.id, d.number, d.issue_date, d.due_date, d.total_minor, d.paid_minor, d.status, p.name as party from acct_docs d left join acct_parties p on p.id = d.party_id and p.tenant_id = d.tenant_id where d.kind = ? order by d.issue_date desc, d.number desc limit ?',
                [$kind, min(300, max(1, $limit))]));
    }

    public function doc(string $id): ?array
    {
        $d = DB::selectOne('select d.id, d.kind, d.number, d.party_id, p.name as party, d.issue_date, d.due_date, d.reference, d.subtotal_minor, d.vat_minor, d.total_minor, d.paid_minor, d.status, d.entry_id, d.void_entry_id, a.name as pay_account from acct_docs d left join acct_parties p on p.id = d.party_id and p.tenant_id = d.tenant_id left join accounts a on a.id = d.pay_account_id where d.id = ?', [$id]);
        if ($d === null) {
            return null;
        }
        $d = (array) $d;
        $d['id'] = trim((string) $d['id']);
        $d['entry_id'] = trim((string) $d['entry_id']);
        $lines = array_map(fn ($r) => (array) $r, DB::select('select l.description, l.amount_minor, a.code, a.name, a.name_bn from acct_doc_lines l join accounts a on a.id = l.account_id and a.tenant_id = l.tenant_id where l.doc_id = ? order by l.seq', [$id]));
        $pays = array_map(fn ($r) => ['id' => trim((string) $r->id)] + (array) $r, DB::select('select p.id, p.pay_date, p.amount_minor, p.note, p.voided_at, a.name as account from acct_payments p join accounts a on a.id = p.account_id and a.tenant_id = p.tenant_id where p.doc_id = ? order by p.created_at', [$id]));

        return ['doc' => $d, 'lines' => $lines, 'payments' => $pays];
    }

    public function pay(string $docId, string $userId, string $date, int $amountMinor, string $accountId, ?string $note): string
    {
        return (string) DB::selectOne('select acct_payment_add(?, ?, ?, ?, ?::date, ?::bigint, ?, ?) as r', [CreateWorkspace::newId(), CreateWorkspace::newId(), $docId, $userId, $date, $amountMinor, $accountId, $note])->r;
    }

    public function voidPayment(string $paymentId, string $userId, string $date): string
    {
        return (string) DB::selectOne('select acct_payment_void(?, ?, ?, ?::date) as r', [CreateWorkspace::newId(), $paymentId, $userId, $date])->r;
    }

    public function voidDoc(string $docId, string $userId, string $date): string
    {
        return (string) DB::selectOne('select acct_doc_void(?, ?, ?, ?::date) as r', [CreateWorkspace::newId(), $docId, $userId, $date])->r;
    }

    public function aging(string $kind, string $asof): array
    {
        return array_map(fn ($r) => ['doc_id' => trim((string) $r->doc_id)] + (array) $r, DB::select('select doc_id, number, party_id, party_name, issue_date, due_date, total_minor, balance_minor, days_overdue, bucket from acct_aging(?, ?::date)', [$kind, $asof]));
    }

    public function cashBalances(string $asof): array
    {
        return array_map(fn ($r) => ['account_id' => trim((string) $r->account_id)] + (array) $r, DB::select('select account_id, code, name, name_bn, balance_minor from acct_cash_balances(?::date)', [$asof]));
    }

    public function markCash(string $accountId, bool $on): string
    {
        return (string) DB::selectOne('select acct_account_cash(?, ?::boolean) as r', [$accountId, $on ? 'true' : 'false'])->r;
    }
}
