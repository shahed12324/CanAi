<?php

declare(strict_types=1);

namespace App\Vat;

use App\Actions\Tenancy\CreateWorkspace;
use Illuminate\Support\Facades\DB;

final class PgTaxCalRepo implements TaxCalRepo
{
    public function obligations(): array
    {
        return array_map(fn ($r) => ['id' => trim((string) $r->id), 'label' => (string) $r->label, 'kind' => (string) $r->kind, 'freq' => (string) $r->freq,
            'due_day' => $r->due_day === null ? null : (int) $r->due_day, 'due_month' => $r->due_month === null ? null : (int) $r->due_month, 'once_date' => $r->once_date === null ? null : (string) $r->once_date,
            'remind_days' => (int) $r->remind_days, 'active' => (bool) $r->active],
            DB::select('select id, label, kind, freq, due_day, due_month, once_date, remind_days, active from tax_obligations order by active desc, label, id'));
    }

    public function doneMap(string $from, string $to): array
    {
        $out = [];
        foreach (DB::select('select obligation_id, due_date, done_on from tax_obligation_done where due_date between ?::date and ?::date', [$from, $to]) as $r) {
            $out[trim((string) $r->obligation_id).'|'.$r->due_date] = (string) $r->done_on;
        }

        return $out;
    }

    public function save(string $tenantId, string $userId, ?string $id, string $label, string $kind, string $freq, ?int $day, ?int $month, ?string $once, int $remind): string
    {
        return (string) DB::selectOne('select tax_obligation_save(?, ?, ?, ?, ?, ?, ?::integer, ?::integer, ?::date, ?::integer) as r',
            [$id ?? CreateWorkspace::newId(), $tenantId, $userId, $label, $kind, $freq, $day, $month, $once, $remind])->r;
    }

    public function setActive(string $tenantId, string $id, bool $active): string
    {
        return (string) DB::selectOne('select tax_obligation_active(?, ?, ?::boolean) as r', [$id, $tenantId, $active ? 'true' : 'false'])->r;
    }

    public function markDone(string $tenantId, string $userId, string $id, string $due, string $doneOn, ?string $note): string
    {
        return (string) DB::selectOne('select tax_cal_done(?, ?, ?, ?::date, ?::date, ?) as r', [$id, $tenantId, $userId, $due, $doneOn, $note])->r;
    }

    public function undo(string $tenantId, string $id, string $due): string
    {
        return (string) DB::selectOne('select tax_cal_undo(?, ?, ?::date) as r', [$id, $tenantId, $due])->r;
    }

    public function seed(string $tenantId, string $userId): string
    {
        $ids = '{'.implode(',', array_map(fn () => CreateWorkspace::newId(), range(1, 5))).'}';

        return (string) DB::selectOne('select tax_cal_seed(?, ?, ?::text[]) as r', [$tenantId, $userId, $ids])->r;
    }
}
