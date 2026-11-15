<?php

declare(strict_types=1);

namespace App\Credits;

/** Turns [label => amount] rows into bar widths (0-100) for the pure-CSS charts. No floats reach the ledger; this is display only. */
final class Bars
{
    /**
     * @param  array<int, array{label: string, total: string|int|float}>  $rows
     * @return array<int, array{label: string, total: string, pct: int}>
     */
    public static function scale(array $rows): array
    {
        $max = 0.0;
        foreach ($rows as $r) {
            $max = max($max, (float) $r['total']);
        }
        $out = [];
        foreach ($rows as $r) {
            $v = (float) $r['total'];
            $pct = $max <= 0.0 ? 0 : (int) max($v > 0 ? 1 : 0, round($v / $max * 100));
            $out[] = ['label' => $r['label'], 'total' => (string) $r['total'], 'pct' => min(100, $pct)];
        }

        return $out;
    }
}
