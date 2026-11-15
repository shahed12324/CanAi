<?php

declare(strict_types=1);

namespace App\Ads;

/** Meta takes budgets in the currency's smallest unit.  Only currencies whose unit is 1/100 (verified list) are accepted — for any other the answer is "unknown", never a guess.  Pure. */
final class AdsMoney
{
    public const HUNDREDTHS = ['BDT', 'USD', 'EUR', 'GBP', 'INR', 'AED', 'SAR', 'MYR', 'SGD', 'AUD', 'CAD'];

    public static function minor(string $amount, string $currency): ?int
    {
        if (! in_array($currency, self::HUNDREDTHS, true) || preg_match('/\A([0-9]{1,9})(?:\.([0-9]{1,2}))?\z/', $amount, $m) !== 1) {
            return null;
        }
        $n = ((int) $m[1]) * 100 + (int) str_pad($m[2] ?? '0', 2, '0');

        return $n > 0 ? $n : null;
    }
}
