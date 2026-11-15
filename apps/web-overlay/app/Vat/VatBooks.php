<?php

declare(strict_types=1);

namespace App\Vat;

/**
 * Purchase / sales registers (Mushak 6.1 / 6.2 style) and the return DRAFT, built from the documents of a period. Pure.
 * This is a working paper from the books, not the NBR form: a person checks it and files on NBR's own system.
 */
final class VatBooks
{
    /**
     * @param list<array<string, mixed>> $docs rows: kind, number, issue_date, party, reference, subtotal_minor, vat_minor, status, creditable (nullable = default yes), reason
     * @return array{sales: list<array<string, mixed>>, purchases: list<array<string, mixed>>, figures: array<string, int>}
     */
    public static function build(array $docs): array
    {
        $sales = [];
        $purchases = [];
        $f = ['sales_base_minor' => 0, 'output_vat_minor' => 0, 'purchase_base_minor' => 0, 'input_vat_claimable_minor' => 0, 'input_vat_blocked_minor' => 0, 'input_vat_minor' => 0, 'net_minor' => 0, 'sales_count' => 0, 'purchase_count' => 0];
        foreach ($docs as $d) {
            if (($d['status'] ?? '') === 'void') {
                continue;
            }
            $sub = (int) $d['subtotal_minor'];
            $vat = (int) $d['vat_minor'];
            if ($d['kind'] === 'invoice') {
                $sales[] = $d;
                $f['sales_base_minor'] += $sub;
                $f['output_vat_minor'] += $vat;
                $f['sales_count']++;
            } elseif (in_array($d['kind'], ['bill', 'expense'], true)) {
                $ok = $vat === 0 || ($d['creditable'] ?? true) !== false;
                $d['creditable'] = $ok;
                $purchases[] = $d;
                $f['purchase_base_minor'] += $sub;
                $f['purchase_count']++;
                if ($ok) {
                    $f['input_vat_claimable_minor'] += $vat;
                } else {
                    $f['input_vat_blocked_minor'] += $vat;
                }
            }
        }
        $f['input_vat_minor'] = $f['input_vat_claimable_minor'];
        $f['net_minor'] = $f['output_vat_minor'] - $f['input_vat_minor'];   // negative = credit to carry forward

        return ['sales' => $sales, 'purchases' => $purchases, 'figures' => $f];
    }

    /**
     * The filing period containing a date: the month, or the calendar quarter.
     *
     * @return array{0: string, 1: string}
     */
    public static function period(string $date, string $filing): array
    {
        [$y, $m] = array_map('intval', explode('-', substr($date, 0, 7)));
        if ($filing === 'quarterly') {
            $m0 = intdiv($m - 1, 3) * 3 + 1;
            $m1 = $m0 + 2;
        } else {
            $m0 = $m1 = $m;
        }
        $last = (int) date('t', (int) mktime(0, 0, 0, $m1, 1, $y));

        return [sprintf('%04d-%02d-01', $y, $m0), sprintf('%04d-%02d-%02d', $y, $m1, $last)];
    }

    /**
     * The same period one step back (for "previous return").
     *
     * @return array{0: string, 1: string}
     */
    public static function previous(string $from, string $filing): array
    {
        return self::period(date('Y-m-d', (int) strtotime($from.' -1 day')), $filing);
    }

    /** CSV of a register. Cells starting with = + - @ are prefixed so a spreadsheet never runs them. @param list<array<string, mixed>> $rows */
    public static function csv(array $rows, bool $purchase): string
    {
        $out = [$purchase ? ['Number', 'Date', 'Supplier', 'Reference', 'Value (৳)', 'VAT (৳)', 'Credit can be taken', 'Reason'] : ['Number', 'Date', 'Customer', 'Reference', 'Value (৳)', 'VAT (৳)']];
        foreach ($rows as $r) {
            $row = [$r['number'], $r['issue_date'], (string) ($r['party'] ?? ''), (string) ($r['reference'] ?? ''), self::taka((int) $r['subtotal_minor']), self::taka((int) $r['vat_minor'])];
            if ($purchase) {
                $row[] = ($r['creditable'] ?? true) ? 'Yes' : 'No';
                $row[] = (string) ($r['reason'] ?? '');
            }
            $out[] = $row;
        }

        return "\xEF\xBB\xBF".implode("\r\n", array_map(fn ($row) => implode(',', array_map([self::class, 'cell'], $row)), $out))."\r\n";
    }

    public static function taka(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function cell(string $v): string
    {
        if ($v !== '' && str_contains("=+-@\t\r", $v[0])) {
            $v = "'".$v;
        }

        return '"'.str_replace('"', '""', $v).'"';
    }
}
