<?php

declare(strict_types=1);

namespace App\Vat;

use App\Accounting\AccText;

/**
 * The "tax withheld" rows of a payment form -> the items the database function checks. The person may type the amount; if left blank it is
 * base x the rate of the chosen rule on that date (the rule is only a helper - the confirmed amount is what is booked). Pure.
 */
final class TaxItems
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  form rows: kind, rule (code), base, amount, cert
     * @param  list<array{kind: string, code: string, rate_bp: int, effective_from: string, effective_to: ?string}>  $rules
     * @return array{error: ?string, items: list<array<string, mixed>>, withheld_minor: int}
     */
    public static function parse(array $rows, int $grossMinor, array $rules, string $date): array
    {
        $items = [];
        $sum = 0;
        foreach (array_slice($rows, 0, 12) as $r) {
            $kind = (string) ($r['kind'] ?? '');
            $baseRaw = trim((string) ($r['base'] ?? ''));
            $amtRaw = trim((string) ($r['amount'] ?? ''));
            $rule = trim((string) ($r['rule'] ?? ''));
            if ($kind === '' && $baseRaw === '' && $amtRaw === '' && $rule === '') {
                continue;
            }
            if (! in_array($kind, ['vds', 'tds'], true)) {
                return self::fail('Pick the type of deducted tax (VDS or TDS).');
            }
            $base = $baseRaw === '' ? $grossMinor : AccText::toMinor($baseRaw);
            if ($base === null) {
                return self::fail('The base amount for tax deduction is not valid.');
            }
            $bp = null;
            foreach ($rules as $x) {
                if ($x['kind'] === $kind && $x['code'] === $rule && $x['effective_from'] <= $date && ($x['effective_to'] === null || $x['effective_to'] >= $date)) {
                    $bp = $x['rate_bp'];
                }
            }
            if ($rule !== '' && $bp === null) {
                return self::fail("\"$rule\" The rule is not active on this date.");
            }
            if ($amtRaw === '') {
                if ($bp === null) {
                    return self::fail('Enter the deducted amount, or pick a rule.');
                }
                $amount = VatCalc::vat($base, $bp);
            } else {
                $amount = AccText::toMinor($amtRaw);
            }
            if ($amount === null || $amount < 1) {
                return self::fail('The deducted tax amount is not valid (must be above 0).');
            }
            $effBp = $bp ?? (int) min(10000, intdiv($amount * 10000 + intdiv($base, 2), max(1, $base)));
            $cert = trim((string) ($r['cert'] ?? ''));
            $items[] = ['kind' => $kind, 'rule_code' => $rule === '' ? null : $rule, 'base_minor' => $base, 'rate_bp' => $effBp, 'amount_minor' => $amount, 'cert_no' => $cert === '' ? null : mb_substr($cert, 0, 40)];
            $sum += $amount;
        }
        if ($items === []) {
            return self::fail('Add at least one deducted tax (if no tax is deducted use a normal payment).');
        }
        if (count($items) > 5) {
            return self::fail('At most 5 tax lines in one payment.');
        }
        if ($sum >= $grossMinor) {
            return self::fail('The deducted tax must be less than the total payment.');
        }

        return ['error' => null, 'items' => $items, 'withheld_minor' => $sum];
    }

    /** @return array{error: string, items: list<array<string, mixed>>, withheld_minor: int} */
    private static function fail(string $e): array
    {
        return ['error' => $e, 'items' => [], 'withheld_minor' => 0];
    }
}
