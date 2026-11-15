<?php

declare(strict_types=1);

namespace App\Analyst;

/**
 * A deliberately plain estimate: a straight-line trend through the last (up to 6) active months, projected 3 months ahead, with a +-1 standard-deviation band.
 * It knows nothing about seasons, Eid, price changes or one-off deals, so everything it returns is flagged `estimate`. Pure - no database, no AI.
 */
final class Forecast
{
    public const MIN_MONTHS = 3;

    public const MAX_MONTHS = 6;

    public const HORIZON = 3;

    /**
     * @param  array<string, mixed>  $facts  from Facts::build
     * @return array<string, mixed>
     */
    public static function build(array $facts): array
    {
        $h = (int) $facts['history_months'];
        if ($h < self::MIN_MONTHS) {
            return ['estimate' => true, 'enough' => false, 'based_on_months' => $h, 'reason' => 'At least '.self::MIN_MONTHS.' months of transaction data are needed — there are now '.$h.' months.'];
        }
        $use = array_slice($facts['months'], -min($h, self::MAX_MONTHS));
        $n = count($use);
        $inc = self::series(array_column($use, 'income_minor'));
        $exp = self::series(array_column($use, 'expense_minor'));
        $csh = self::series(array_column($use, 'cash_net_minor'));
        $next = Facts::months(self::after((string) $facts['month'], self::HORIZON), self::HORIZON);
        $rows = [];
        $cum = (int) $facts['cash_end_minor'];
        $sumCash = 0;
        foreach ($next as $i => $ym) {
            $k = $n + $i;
            $in = self::at($inc, $k, true);
            $ex = self::at($exp, $k, true);
            $ca = self::at($csh, $k, false);
            $cum += $ca['mid'];
            $sumCash += $ca['mid'];
            $rows[] = ['ym' => $ym, 'income_minor' => $in['mid'], 'income_low_minor' => $in['low'], 'income_high_minor' => $in['high'], 'expense_minor' => $ex['mid'], 'expense_low_minor' => $ex['low'], 'expense_high_minor' => $ex['high'],
                'profit_minor' => $in['mid'] - $ex['mid'], 'profit_low_minor' => $in['low'] - $ex['high'], 'profit_high_minor' => $in['high'] - $ex['low'], 'cash_net_minor' => $ca['mid'], 'cash_end_minor' => $cum];
        }
        $avgCash = intdiv($sumCash, self::HORIZON);
        $cashEnd = (int) $facts['cash_end_minor'];

        return ['estimate' => true, 'enough' => true, 'based_on_months' => $n, 'method' => 'Straight-line trend (last '.$n.' months), ±1 standard deviation', 'months' => $rows,
            'avg_income_minor' => (int) round(array_sum(array_column($rows, 'income_minor')) / self::HORIZON), 'avg_expense_minor' => (int) round(array_sum(array_column($rows, 'expense_minor')) / self::HORIZON),
            'runway_months' => $avgCash < 0 && $cashEnd > 0 ? round($cashEnd / -$avgCash, 1) : null];
    }

    /** The month `$k` months after $monthTo, as 'YYYY-MM' (so Facts::months(.., $k) ends there). */
    public static function after(string $monthTo, int $k): string
    {
        [$y, $m] = array_map('intval', explode('-', $monthTo));
        $t = $y * 12 + ($m - 1) + $k;

        return sprintf('%04d-%02d', intdiv($t, 12), $t % 12 + 1);
    }

    /**
     * Least squares on x = 0..n-1.
     *
     * @param  list<int>  $y
     * @return array{a: float, b: float, sd: float}
     */
    private static function series(array $y): array
    {
        $n = count($y);
        $mx = ($n - 1) / 2;
        $my = array_sum($y) / $n;
        $sxx = 0.0;
        $sxy = 0.0;
        foreach ($y as $i => $v) {
            $sxx += ($i - $mx) ** 2;
            $sxy += ($i - $mx) * ($v - $my);
        }
        $b = $sxx > 0 ? $sxy / $sxx : 0.0;
        $a = $my - $b * $mx;
        $ss = 0.0;
        foreach ($y as $i => $v) {
            $ss += ($v - ($a + $b * $i)) ** 2;
        }

        return ['a' => $a, 'b' => $b, 'sd' => $n > 2 ? sqrt($ss / ($n - 2)) : 0.0];
    }

    /**
     * @param  array{a: float, b: float, sd: float}  $s
     * @return array{mid: int, low: int, high: int}
     */
    private static function at(array $s, int $x, bool $nonNegative): array
    {
        $mid = $s['a'] + $s['b'] * $x;
        $low = $mid - $s['sd'];
        $high = $mid + $s['sd'];
        if ($nonNegative) {
            $mid = max(0.0, $mid);
            $low = max(0.0, $low);
            $high = max(0.0, $high);
        }

        return ['mid' => (int) round($mid), 'low' => (int) round($low), 'high' => (int) round($high)];
    }
}
