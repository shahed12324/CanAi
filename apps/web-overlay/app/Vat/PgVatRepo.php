<?php

declare(strict_types=1);

namespace App\Vat;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgVatRepo implements VatRepo
{
    public function settings(): array
    {
        $r = DB::selectOne('select registered, bin, filing_period from vat_settings limit 1');

        return $r === null ? ['registered' => false, 'bin' => null, 'filing_period' => 'monthly'] : ['registered' => (bool) $r->registered, 'bin' => $r->bin, 'filing_period' => (string) $r->filing_period];
    }

    public function saveSettings(string $tenantId, string $userId, bool $registered, ?string $bin, string $period): string
    {
        return (string) DB::selectOne('select vat_settings_save(?, ?, ?::boolean, ?, ?) as r', [$tenantId, $userId, $registered ? 'true' : 'false', $bin, $period])->r;
    }

    public function rates(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'code' => (string) $r->code, 'label' => (string) $r->label, 'rate_bp' => (int) $r->rate_bp, 'effective_from' => (string) $r->effective_from, 'effective_to' => $r->effective_to === null ? null : (string) $r->effective_to],
            DB::select('select id, code, label, rate_bp, effective_from, effective_to from vat_rates order by code, effective_from desc'));
    }

    public function seedRates(string $tenantId, string $userId): string
    {
        return (string) DB::selectOne('select vat_rate_seed(?, ?) as r', [$tenantId, $userId])->r;
    }

    public function saveRate(string $tenantId, string $userId, string $code, string $label, int $bp, string $from): string
    {
        return (string) DB::selectOne('select vat_rate_save(?, ?, ?, ?, ?, ?::integer, ?::date) as r', [CreateWorkspace::newId(), $tenantId, $userId, $code, $label, $bp, $from])->r;
    }

    public function docs(string $from, string $to): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'creditable' => $r->creditable === null ? null : (bool) $r->creditable] + (array) $r,
            DB::select("select d.id, d.kind, d.number, d.issue_date, d.reference, d.subtotal_minor, d.vat_minor, d.status, p.name as party, f.creditable, f.reason from acct_docs d left join acct_parties p on p.id = d.party_id and p.tenant_id = d.tenant_id left join vat_doc_flags f on f.doc_id = d.id and f.tenant_id = d.tenant_id where d.issue_date between ?::date and ?::date order by d.issue_date, d.number", [$from, $to]));
    }

    public function flagDoc(string $docId, string $userId, bool $creditable, ?string $reason): string
    {
        return (string) DB::selectOne('select vat_doc_flag(?, ?, ?::boolean, ?) as r', [$docId, $userId, $creditable ? 'true' : 'false', $reason])->r;
    }

    public function saveReturn(string $tenantId, string $userId, string $from, string $to, array $figures, ?string $note): array
    {
        $r = DB::selectOne('select result, return_id from vat_return_save(?, ?, ?, ?::date, ?::date, ?::jsonb, ?)', [CreateWorkspace::newId(), $tenantId, $userId, $from, $to, json_encode($figures, JSON_THROW_ON_ERROR), $note]);

        return ['result' => (string) $r->result, 'return_id' => $r->return_id === null ? null : trim((string) $r->return_id)];
    }

    public function fileReturn(string $returnId, string $userId, string $on, ?string $ref): string
    {
        return (string) DB::selectOne('select vat_return_file(?, ?, ?::date, ?) as r', [$returnId, $userId, $on, $ref])->r;
    }

    public function returns(int $limit): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'figures' => json_decode((string) $r->figures, true)] + (array) $r,
            DB::select('select id, period_from, period_to, status, figures, note, filed_on, filed_ref, updated_at from vat_returns order by period_from desc, status desc limit ?', [min(100, max(1, $limit))]));
    }

    public function savedReturn(string $from, string $to, string $status): ?array
    {
        $r = DB::selectOne('select id, status, figures, note, filed_on, filed_ref from vat_returns where period_from = ?::date and period_to = ?::date and status = ?', [$from, $to, $status]);

        return $r === null ? null : ['id' => trim((string) $r->id), 'figures' => json_decode((string) $r->figures, true)] + (array) $r;
    }
}
