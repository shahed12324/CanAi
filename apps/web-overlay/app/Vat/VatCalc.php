<?php

declare(strict_types=1);

namespace App\Vat;

/** VAT arithmetic in integer poisha and basis points, and the effective-dated rate lookup. Pure. */
final class VatCalc
{
    /** subtotal x rate, rounded half-up to a whole poisha. 100000 poisha at 1500 bp = 15000. */
    public static function vat(int $subtotalMinor, int $rateBp): int
    {
        if ($subtotalMinor < 0 || $rateBp < 0 || $rateBp > 10000) {
            throw new \InvalidArgumentException('bad VAT input');
        }

        return intdiv($subtotalMinor * $rateBp + 5000, 10000);
    }

    /**
     * The rate in force for a code on a date, or null when none covers it.
     *
     * @param list<array{code: string, rate_bp: int, effective_from: string, effective_to: ?string}> $rates
     */
    public static function rateAt(array $rates, string $code, string $date): ?int
    {
        foreach ($rates as $r) {
            if ($r['code'] === $code && $r['effective_from'] <= $date && ($r['effective_to'] === null || $r['effective_to'] >= $date)) {
                return $r['rate_bp'];
            }
        }

        return null;
    }

    /** 1500 -> "15%", 750 -> "7.5%", 125 -> "1.25%". */
    public static function percent(int $bp): string
    {
        $s = rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.');

        return ($s === '' ? '0' : $s).'%';
    }

    /** "7.5" / "১৫" / "15%" -> basis points; null when not a number from 0 to 100 with at most 2 decimals. */
    public static function parsePercent(string $v): ?int
    {
        $v = strtr(trim(rtrim(trim($v), '%')), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        if (preg_match('/\A(\d{1,3})(?:\.(\d{1,2}))?\z/', $v, $m) !== 1) {
            return null;
        }
        $bp = (int) $m[1] * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $bp > 10000 ? null : $bp;
    }
}
