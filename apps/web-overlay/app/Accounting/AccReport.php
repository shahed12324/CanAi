<?php

declare(strict_types=1);

namespace App\Accounting;

/** Turns the rows of the three statement functions into the sections and totals the screens show. Pure. */
final class AccReport
{
    /**
     * Profit & loss. Sales/other income, cost of goods (codes 5xxx), operating expenses (everything else), non-operating (7xxx).
     *
     * @param list<array{code: string, type: string, amount_minor: int}> $rows
     * @return array{income: list<array>, cogs: list<array>, opex: list<array>, nonop: list<array>, totals: array<string, int>}
     */
    public static function profitLoss(array $rows): array
    {
        $g = ['income' => [], 'cogs' => [], 'opex' => [], 'nonop' => []];
        foreach ($rows as $r) {
            $k = $r['type'] === 'income' ? 'income' : (str_starts_with($r['code'], '5') ? 'cogs' : (str_starts_with($r['code'], '7') ? 'nonop' : 'opex'));
            $g[$k][] = $r;
        }
        $sum = fn (array $l) => (int) array_sum(array_map(fn ($r) => (int) $r['amount_minor'], $l));
        $income = $sum($g['income']);
        $cogs = $sum($g['cogs']);
        $opex = $sum($g['opex']);
        $nonop = $sum($g['nonop']);
        $gross = $income - $cogs;
        $operating = $gross - $opex;

        return $g + ['totals' => ['income' => $income, 'cogs' => $cogs, 'gross' => $gross, 'opex' => $opex, 'operating' => $operating, 'nonop' => $nonop, 'net' => $operating - $nonop]];
    }

    /**
     * @param list<array{section: string, amount_minor: int}> $rows
     * @return array{asset: list<array>, liability: list<array>, equity: list<array>, earnings: int, totals: array<string, int>, balanced: bool}
     */
    public static function balanceSheet(array $rows): array
    {
        $g = ['asset' => [], 'liability' => [], 'equity' => []];
        $earnings = 0;
        foreach ($rows as $r) {
            if ($r['section'] === 'earnings') {
                $earnings += (int) $r['amount_minor'];
            } elseif (isset($g[$r['section']])) {
                $g[$r['section']][] = $r;
            }
        }
        $sum = fn (array $l) => (int) array_sum(array_map(fn ($r) => (int) $r['amount_minor'], $l));
        $assets = $sum($g['asset']);
        $liab = $sum($g['liability']);
        $eq = $sum($g['equity']) + $earnings;

        return $g + ['earnings' => $earnings, 'totals' => ['assets' => $assets, 'liabilities' => $liab, 'equity' => $eq, 'liabilities_equity' => $liab + $eq], 'balanced' => $assets === $liab + $eq];
    }

    /**
     * @param list<array{section: string, amount_minor: int}> $rows
     * @return array{opening: int, closing: int, operating: list<array>, investing: list<array>, financing: list<array>, totals: array<string, int>, reconciled: bool}
     */
    public static function cashFlow(array $rows): array
    {
        $g = ['operating' => [], 'investing' => [], 'financing' => []];
        $opening = 0;
        $closing = 0;
        foreach ($rows as $r) {
            if ($r['section'] === 'opening') {
                $opening += (int) $r['amount_minor'];
            } elseif ($r['section'] === 'closing') {
                $closing += (int) $r['amount_minor'];
            } elseif (isset($g[$r['section']])) {
                $g[$r['section']][] = $r;
            }
        }
        $sum = fn (array $l) => (int) array_sum(array_map(fn ($r) => (int) $r['amount_minor'], $l));
        $t = ['operating' => $sum($g['operating']), 'investing' => $sum($g['investing']), 'financing' => $sum($g['financing'])];
        $t['net'] = $t['operating'] + $t['investing'] + $t['financing'];

        return $g + ['opening' => $opening, 'closing' => $closing, 'totals' => $t, 'reconciled' => $opening + $t['net'] === $closing];
    }

    /**
     * Period presets, Bangladesh fiscal year (1 July - 30 June). Anything else is "custom" and the caller's own dates are used.
     *
     * @return array{0: string, 1: string}
     */
    public static function range(string $preset, string $today): array
    {
        $d = new \DateTimeImmutable($today);
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n');

        return match ($preset) {
            'last_month' => [$d->modify('first day of last month')->format('Y-m-d'), $d->modify('last day of last month')->format('Y-m-d')],
            'fiscal_year' => [sprintf('%04d-07-01', $m >= 7 ? $y : $y - 1), $today],
            'last_fiscal_year' => [sprintf('%04d-07-01', $m >= 7 ? $y - 1 : $y - 2), sprintf('%04d-06-30', $m >= 7 ? $y : $y - 1)],
            'calendar_year' => [sprintf('%04d-01-01', $y), $today],
            default => [$d->format('Y-m-01'), $today],
        };
    }

    public static function presetBn(string $p): string
    {
        return ['month' => 'This month', 'last_month' => 'Last month', 'fiscal_year' => 'This fiscal year (July–June)', 'last_fiscal_year' => 'Last fiscal year', 'calendar_year' => 'This year (January–'][$p] ?? 'Custom date';
    }

    /** A valid Y-m-d, or the fallback. */
    public static function day(mixed $v, string $fallback): string
    {
        if (is_string($v) && preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 2000) {
            return $v;
        }

        return $fallback;
    }
}
