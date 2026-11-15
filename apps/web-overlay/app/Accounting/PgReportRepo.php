<?php

declare(strict_types=1);

namespace App\Accounting;

use Illuminate\Support\Facades\DB;

final class PgReportRepo implements ReportRepo
{
    public function profitLoss(string $from, string $to): array
    {
        return array_map(fn ($r) => ['account_id' => trim((string) $r->account_id), 'code' => (string) $r->code, 'name' => (string) $r->name, 'name_bn' => $r->name_bn, 'type' => (string) $r->type, 'system_key' => $r->system_key, 'amount_minor' => (int) $r->amount_minor],
            DB::select('select account_id, code, name, name_bn, type, system_key, amount_minor from acct_profit_loss(?::date, ?::date)', [$from, $to]));
    }

    public function balanceSheet(string $asof): array
    {
        return $this->rows(DB::select('select section, account_id, code, name, name_bn, amount_minor from acct_balance_sheet(?::date)', [$asof]));
    }

    public function cashFlow(string $from, string $to): array
    {
        return $this->rows(DB::select('select section, account_id, code, name, name_bn, amount_minor from acct_cash_flow(?::date, ?::date)', [$from, $to]));
    }

    /** @param array<int, object> $rows @return list<array<string, mixed>> */
    private function rows(array $rows): array
    {
        return array_map(fn ($r) => ['section' => (string) $r->section, 'account_id' => $r->account_id === null ? null : trim((string) $r->account_id), 'code' => $r->code, 'name' => (string) $r->name, 'name_bn' => $r->name_bn, 'amount_minor' => (int) $r->amount_minor], $rows);
    }
}
