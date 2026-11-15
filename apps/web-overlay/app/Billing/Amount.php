<?php

declare(strict_types=1);

namespace App\Billing;

/** Integer minor units (paisa / cents, 2 decimals for every currency we sell in) <-> the decimal strings gateways speak. No floats. */
final class Amount
{
    public static function toDecimal(int $minor): string
    {
        $neg = $minor < 0;
        $minor = abs($minor);

        return ($neg ? '-' : '').intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    /** "1150", "1150.5", "1150.50", 1150 -> minor units; null for anything else (more than 2 decimals, signs, exponents, junk). */
    public static function fromDecimal(mixed $value): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            $value = number_format($value, 2, '.', '');
        }
        if (! is_string($value) || preg_match('/\A(\d{1,12})(?:\.(\d{1,2})0*)?\z/', trim($value), $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 100 + (isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0);
    }
}
