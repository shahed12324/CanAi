<?php

declare(strict_types=1);

namespace App\Payroll;

use App\Crm\LeadCsv;
use App\Vat\VatBooks;

/**
 * Builds the salary payment file from a posted/paid run and each employee's pay details. Pure - no database.
 * One file per channel (bank / bkash / nagad); cash staff and staff with no details are only reported, never put in a file.
 * Bank formats differ by bank, so this is a generic CSV the person maps to their bank's template.
 */
final class SalaryFile
{
    public const METHODS = ['bank' => 'Bank', 'bkash' => 'bKash', 'nagad' => 'cash', 'cash' => 'Cash (in hand)'];

    /**
     * @param  list<array{employee_id: string, code: string, name: string, net_minor: int}>  $lines
     * @param  array<string, array{method: string, bank_name: ?string, branch: ?string, routing_no: ?string, account_no: ?string, wallet_no: ?string}>  $details  keyed by employee id
     * @return array{rows: array<string, list<array<string, mixed>>>, totals: array<string, int>, missing: list<array<string, mixed>>}
     *                                                                                                                                  rows/totals keyed by method (bank|bkash|nagad|cash); missing = lines with money to pay but no pay details
     */
    public static function plan(array $lines, array $details): array
    {
        $rows = ['bank' => [], 'bkash' => [], 'nagad' => [], 'cash' => []];
        $totals = ['bank' => 0, 'bkash' => 0, 'nagad' => 0, 'cash' => 0];
        $missing = [];
        foreach ($lines as $l) {
            if ($l['net_minor'] <= 0) {
                continue;
            }
            $d = $details[$l['employee_id']] ?? null;
            if ($d === null || ! isset($rows[$d['method']])) {
                $missing[] = $l;

                continue;
            }
            $rows[$d['method']][] = $l + ['detail' => $d];
            $totals[$d['method']] += $l['net_minor'];
        }

        return ['rows' => $rows, 'totals' => $totals, 'missing' => $missing];
    }

    /** CSV for one channel, or null when the channel has no payable rows or is not a file channel (cash). */
    public static function csv(string $method, array $planRows, string $period): ?string
    {
        if ($planRows === [] || ! in_array($method, ['bank', 'bkash', 'nagad'], true)) {
            return null;
        }
        $ref = 'Salary '.$period;
        if ($method === 'bank') {
            $out = [['Code', 'Name', 'Bank', 'Branch', 'Routing number', 'Account number', 'Net salary (taka)', 'Reference']];
            foreach ($planRows as $r) {
                $d = $r['detail'];
                $out[] = [$r['code'], $r['name'], (string) $d['bank_name'], (string) $d['branch'], (string) $d['routing_no'], (string) $d['account_no'], VatBooks::taka($r['net_minor']), $ref];
            }
        } else {
            $out = [['Code', 'Name', 'Wallet number', 'Net salary (taka)', 'Reference']];
            foreach ($planRows as $r) {
                $out[] = [$r['code'], $r['name'], (string) $r['detail']['wallet_no'], VatBooks::taka($r['net_minor']), $ref];
            }
        }

        return LeadCsv::toCsv($out);
    }

    /** Keeps only the last 4 characters: 01712345678 -> •••••••5678 (screen display; never the full number). */
    public static function mask(?string $n): string
    {
        $n = (string) $n;
        $len = strlen($n);
        if ($len <= 4) {
            return str_repeat('•', $len);
        }

        return str_repeat('•', $len - 4).substr($n, -4);
    }
}
