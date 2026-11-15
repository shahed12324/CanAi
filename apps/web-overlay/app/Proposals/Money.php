<?php

declare(strict_types=1);

namespace App\Proposals;

/** Money as integer paisa (1 taka = 100). Parses what people type; prints Bangladeshi style (lakh grouping, Bangla digits) or plain. */
final class Money
{
    public const MAX_TAKA = 100_000_000;

    private const BN = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    public static function toLatinDigits(string $s): string
    {
        return strtr($s, array_flip(self::BN) + []);
    }

    public static function toBnDigits(string $s): string
    {
        return strtr($s, ['0' => '০', '1' => '১', '2' => '২', '3' => '৩', '4' => '৪', '5' => '৫', '6' => '৬', '7' => '৭', '8' => '৮', '9' => '৯']);
    }

    /** "12,500", "৳ ১২৫০০.৫০", "BDT 50000", "1500 tk" -> paisa; null when it is not a plain non-negative amount up to 100,000,000. */
    public static function parse(string $input): ?int
    {
        $s = self::toLatinDigits(trim($input));
        $s = preg_replace('/(?:৳|bdt|tk\.?|taka|টাকা)/iu', '', $s) ?? $s;
        $s = str_replace([',', ' ', "\u{00A0}"], '', $s);
        if (preg_match('/\A(\d{1,9})(?:\.(\d{1,2}))?\z/', $s, $m) !== 1) {
            return null;
        }
        $whole = (int) $m[1];
        if ($whole > self::MAX_TAKA) {
            return null;
        }
        $frac = isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0;
        if ($whole === self::MAX_TAKA && $frac > 0) {
            return null;
        }

        return $whole * 100 + $frac;
    }

    public static function format(int $paisa, string $lang): string
    {
        $neg = $paisa < 0;
        $paisa = abs($paisa);
        $whole = (string) intdiv($paisa, 100);
        $cents = $paisa % 100;
        if ($lang === 'bn') {
            $g = strlen($whole) > 3
                ? (preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', substr($whole, 0, -3)) ?? '').','.substr($whole, -3)
                : $whole;
            $out = '৳ '.self::toBnDigits($g.($cents > 0 ? '.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT) : ''));
        } else {
            $out = 'BDT '.number_format((int) $whole).($cents > 0 ? '.'.str_pad((string) $cents, 2, '0', STR_PAD_LEFT) : '');
        }

        return $neg ? '-'.$out : $out;
    }

    public static function formatNumber(int $n, string $lang): string
    {
        return $lang === 'bn' ? self::toBnDigits((string) $n) : (string) $n;
    }
}
