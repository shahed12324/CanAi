<?php

declare(strict_types=1);

namespace App\Analyst;

/**
 * Turns raw monthly journal totals into the facts the analyst talks about. Pure arithmetic over what is in the books - no AI, no guessing.
 * Money is integer poisha. Months are 'YYYY-MM'. The last month is always a COMPLETE month (the running month is left out so it never looks like a collapse).
 */
final class Facts
{
    public const WINDOW = 12;

    /** @return list<string> $n months ending at $monthTo, oldest first */
    public static function months(string $monthTo, int $n = self::WINDOW): array
    {
        [$y, $m] = array_map('intval', explode('-', $monthTo));
        $out = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $t = $y * 12 + ($m - 1) - $i;
            $out[] = sprintf('%04d-%02d', intdiv($t, 12), $t % 12 + 1);
        }

        return $out;
    }

    /** Account names come from user data and go into a prompt: one line, no control characters, short. */
    public static function cleanName(string $s): string
    {
        $s = trim((string) preg_replace('/[\p{C}\s]+/u', ' ', $s));

        return mb_substr($s, 0, 40);
    }

    /**
     * @param  list<array{ym: string, type: string, account_id: string, name: string, amount_minor: int}>  $rows  income / expense totals per month and account
     * @param  list<array{ym: string, amount_minor: int}>  $cashRows  net movement of cash/bank/wallet accounts per month
     * @return array<string, mixed>
     */
    public static function build(string $monthTo, array $rows, array $cashRows, int $cashEnd, int $receivable, int $payable): array
    {
        $yms = self::months($monthTo);
        $inc = $exp = $cash = array_fill_keys($yms, 0);
        $acc = [];
        foreach ($rows as $r) {
            if (! isset($inc[$r['ym']]) || ! in_array($r['type'], ['income', 'expense'], true)) {
                continue;
            }
            if ($r['type'] === 'income') {
                $inc[$r['ym']] += $r['amount_minor'];
            } else {
                $exp[$r['ym']] += $r['amount_minor'];
            }
            $acc[$r['ym']][$r['type']][$r['account_id']] = ['name' => self::cleanName($r['name']), 'amount' => $r['amount_minor']];
        }
        foreach ($cashRows as $r) {
            if (isset($cash[$r['ym']])) {
                $cash[$r['ym']] += $r['amount_minor'];
            }
        }
        $months = [];
        foreach ($yms as $ym) {
            $months[] = ['ym' => $ym, 'income_minor' => $inc[$ym], 'expense_minor' => $exp[$ym], 'profit_minor' => $inc[$ym] - $exp[$ym], 'cash_net_minor' => $cash[$ym]];
        }
        $first = null;
        foreach ($months as $i => $m) {
            if ($m['income_minor'] !== 0 || $m['expense_minor'] !== 0) {
                $first = $i;
                break;
            }
        }
        $history = $first === null ? 0 : count($months) - $first;
        $lat = $months[count($months) - 1];
        $prev = $months[count($months) - 2];

        $f = [
            'month' => $monthTo,
            'history_months' => $history,
            'months' => $months,
            'latest' => $lat,
            'income_change_pct' => self::pct($lat['income_minor'] - $prev['income_minor'], $prev['income_minor']),
            'expense_change_pct' => self::pct($lat['expense_minor'] - $prev['expense_minor'], $prev['expense_minor']),
            'margin_pct' => $lat['income_minor'] > 0 ? round($lat['profit_minor'] / $lat['income_minor'] * 100, 1) : null,
            'cash_end_minor' => $cashEnd,
            'receivable_minor' => $receivable,
            'payable_minor' => $payable,
            'loss_streak' => self::lossStreak($months),
            'avg3' => null,
            'expense_up' => [],
            'expense_down' => [],
            'top_income' => [],
        ];
        if ($history >= 4) {
            $three = array_slice($months, -4, 3);
            $f['avg3'] = ['income_minor' => self::avg(array_column($three, 'income_minor')), 'expense_minor' => self::avg(array_column($three, 'expense_minor')), 'profit_minor' => self::avg(array_column($three, 'profit_minor'))];
        }
        $now = $acc[$monthTo]['expense'] ?? [];
        $before = $acc[$prev['ym']]['expense'] ?? [];
        $moves = [];
        foreach ($now + $before as $id => $_) {
            $a = $now[$id]['amount'] ?? 0;
            $b = $before[$id]['amount'] ?? 0;
            $moves[] = ['name' => ($now[$id] ?? $before[$id])['name'], 'now_minor' => $a, 'before_minor' => $b, 'change_minor' => $a - $b];     // unchanged lines (0) are dropped by the filters below
        }
        usort($moves, fn ($x, $y) => $y['change_minor'] <=> $x['change_minor'] ?: strcmp($x['name'], $y['name']));
        $f['expense_up'] = array_slice(array_values(array_filter($moves, fn ($m) => $m['change_minor'] > 0)), 0, 3);
        $f['expense_down'] = array_slice(array_reverse(array_values(array_filter($moves, fn ($m) => $m['change_minor'] < 0))), 0, 3);
        $top = array_values($acc[$monthTo]['income'] ?? []);
        usort($top, fn ($x, $y) => $y['amount'] <=> $x['amount'] ?: strcmp($x['name'], $y['name']));
        foreach (array_slice($top, 0, 3) as $t) {
            if ($t['amount'] > 0 && $lat['income_minor'] > 0) {
                $f['top_income'][] = ['name' => $t['name'], 'amount_minor' => $t['amount'], 'share_pct' => round($t['amount'] / $lat['income_minor'] * 100, 1)];
            }
        }

        return $f;
    }

    private static function pct(int $diff, int $base): ?float
    {
        return $base > 0 ? round($diff / $base * 100, 1) : null;
    }

    /** @param list<int> $v */
    private static function avg(array $v): int
    {
        return (int) round(array_sum($v) / max(1, count($v)));
    }

    /** @param list<array<string, mixed>> $months  months before the first transaction have profit 0, so they end the streak by themselves */
    private static function lossStreak(array $months): int
    {
        $n = 0;
        for ($i = count($months) - 1; $i >= 0; $i--) {
            if ($months[$i]['profit_minor'] >= 0) {
                break;
            }
            $n++;
        }

        return $n;
    }
}
