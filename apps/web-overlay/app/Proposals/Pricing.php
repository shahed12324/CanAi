<?php

declare(strict_types=1);

namespace App\Proposals;

/** The price table of a proposal. Integer paisa all the way; percentages in basis points (15% = 1500); half-up rounding. */
final class Pricing
{
    public const MAX_ITEMS = 50;

    public const MAX_QTY = 10_000;

    /**
     * One item per line: "Name | Quantity | Unit price" (quantity may be left out: "Name | Value").
     *
     * @return array{items: list<array{name: string, qty: int, unit_minor: int}>, errors: list<array{line: int, reason: string}>}
     */
    public static function parseLines(string $text): array
    {
        $items = [];
        $errors = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $i => $raw) {
            if (trim($raw) === '') {
                continue;
            }
            $line = $i + 1;
            if (count($items) >= self::MAX_ITEMS) {
                $errors[] = ['line' => $line, 'reason' => 'max '.self::MAX_ITEMS.' items'];

                break;
            }
            $cols = array_map('trim', explode('|', $raw));
            $name = $cols[0];
            if (count($cols) < 2 || count($cols) > 3 || $name === '' || mb_strlen($name) > 240) {
                $errors[] = ['line' => $line, 'reason' => 'Name | Quantity | Price — write in this form'];

                continue;
            }
            $qty = 1;
            if (count($cols) === 3) {
                $q = Money::toLatinDigits($cols[1]);
                if (preg_match('/\A\d{1,5}\z/', $q) !== 1 || (int) $q < 1 || (int) $q > self::MAX_QTY) {
                    $errors[] = ['line' => $line, 'reason' => 'Quantity must be a whole number from 1 to '.self::MAX_QTY.' within'];

                    continue;
                }
                $qty = (int) $q;
            }
            $unit = Money::parse($cols[count($cols) - 1]);
            if ($unit === null) {
                $errors[] = ['line' => $line, 'reason' => 'The price is not valid'];

                continue;
            }
            $items[] = ['name' => $name, 'qty' => $qty, 'unit_minor' => $unit];
        }

        return ['items' => $items, 'errors' => $errors];
    }

    /** The inverse of parseLines, for filling the editor: plain amounts, no symbols. @param list<array{name: string, qty: int, unit_minor: int}> $items */
    public static function toLines(array $items): string
    {
        return implode("\n", array_map(function (array $it): string {
            $m = $it['unit_minor'];

            return $it['name'].' | '.$it['qty'].' | '.intdiv($m, 100).($m % 100 > 0 ? '.'.str_pad((string) ($m % 100), 2, '0', STR_PAD_LEFT) : '');
        }, $items));
    }

    /** "15", "7.5", "১৫%" -> basis points; empty -> 0; null when invalid or above 100%. */
    public static function parsePercent(string $input): ?int
    {
        $s = trim(Money::toLatinDigits($input));
        $s = rtrim($s, "% \t");
        if ($s === '') {
            return 0;
        }
        if (preg_match('/\A(\d{1,3})(?:\.(\d{1,2}))?\z/', $s, $m) !== 1) {
            return null;
        }
        $bp = (int) $m[1] * 100 + (isset($m[2]) ? (int) str_pad($m[2], 2, '0') : 0);

        return $bp > 10_000 ? null : $bp;
    }

    public static function formatPercent(int $bp, string $lang): string
    {
        $s = $bp % 100 === 0 ? (string) intdiv($bp, 100) : rtrim(number_format($bp / 100, 2, '.', ''), '0');

        return $lang === 'bn' ? Money::toBnDigits($s) : $s;
    }

    /** x * bp / 10000 rounded half up, without ever forming a product that can overflow. */
    public static function mulBp(int $x, int $bp): int
    {
        return intdiv($x, 10_000) * $bp + intdiv(($x % 10_000) * $bp + 5_000, 10_000);
    }

    /**
     * @param  list<array{name: string, qty: int, unit_minor: int}>  $items
     * @return array{rows: list<array{name: string, qty: int, unit_minor: int, total_minor: int}>, subtotal: int, discount: int, vat: int, total: int}
     */
    public static function compute(array $items, int $discountBp, int $vatBp): array
    {
        $rows = [];
        $subtotal = 0;
        foreach ($items as $it) {
            $line = $it['qty'] * $it['unit_minor'];
            $rows[] = $it + ['total_minor' => $line];
            $subtotal += $line;
        }
        $discount = self::mulBp($subtotal, $discountBp);
        $vat = self::mulBp($subtotal - $discount, $vatBp);

        return ['rows' => $rows, 'subtotal' => $subtotal, 'discount' => $discount, 'vat' => $vat, 'total' => $subtotal - $discount + $vat];
    }
}
