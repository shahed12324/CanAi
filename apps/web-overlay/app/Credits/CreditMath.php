<?php

declare(strict_types=1);

namespace App\Credits;

use InvalidArgumentException;

/**
 * Credit amounts are decimal STRINGS with at most 4 fraction digits (numeric(20,4) in the database).
 * Floats are rejected on purpose: 0.1 + 0.2 must never reach the ledger.
 */
final class CreditMath
{
    public const MAX_INT_DIGITS = 16;

    /** "12", "12.5", 12 -> "12.0000"; throws on floats, negatives, junk, >4 decimals. */
    public static function normalize(string|int $value): string
    {
        $s = is_int($value) ? (string) $value : trim($value);
        if (preg_match('/\A(\d{1,'.self::MAX_INT_DIGITS.'})(?:\.(\d{1,4}))?\z/', $s, $m) !== 1) {
            throw new InvalidArgumentException('Invalid credit amount.');
        }

        return $m[1].'.'.str_pad($m[2] ?? '', 4, '0');
    }

    public static function isPositive(string $normalized): bool
    {
        return trim(str_replace(['0', '.'], '', $normalized)) !== '';
    }

    /** Human display: thousands separators, trailing zeros trimmed ("1,250.5"). Input from the database. */
    public static function format(string|int|null $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }
        $neg = str_starts_with((string) $value, '-');
        $s = ltrim((string) $value, '-');
        [$int, $frac] = array_pad(explode('.', $s, 2), 2, '');
        $frac = rtrim($frac, '0');
        $int = ltrim($int, '0') === '' ? '0' : ltrim($int, '0');
        $int = strrev(implode(',', str_split(strrev($int), 3)));

        return ($neg && ($int !== '0' || $frac !== '') ? '-' : '').$int.($frac !== '' ? '.'.$frac : '');
    }

    /** "12.5000" -> 125000 (credits x 10^4) so arithmetic stays in integers. */
    public static function toUnits(string|int $value): int
    {
        return (int) str_replace('.', '', self::normalize($value));
    }

    /** 125000 -> "12.5000". */
    public static function fromUnits(int $units): string
    {
        if ($units < 0) {
            throw new InvalidArgumentException('Negative credit amount.');
        }
        $s = str_pad((string) $units, 5, '0', STR_PAD_LEFT);

        return substr($s, 0, -4).'.'.substr($s, -4);
    }

    /** Plain machine form without thousands separators ("1250.5"), for form fields that are posted back. */
    public static function plain(string|int|null $value): string
    {
        return str_replace(',', '', self::format($value));
    }

    /** Idempotency keys: 8..128 chars of [A-Za-z0-9:_.-]. */
    public static function validKey(string $key): bool
    {
        return preg_match('/\A[A-Za-z0-9:_.\-]{8,128}\z/', $key) === 1;
    }
}
