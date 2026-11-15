<?php

declare(strict_types=1);

namespace App\Crm;

/**
 * Light phone clean-up for CSV import (the crawler uses libphonenumber; this is only for what a user typed in a file).
 * Bangladeshi mobiles are accepted in the usual written forms; other numbers only when they carry a + country code.
 */
final class BdPhone
{
    public static function normalize(string $raw): ?string
    {
        $s = trim($raw);
        $s = strtr($s, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        $plus = str_starts_with($s, '+');
        $d = preg_replace('/\D+/', '', $s) ?? '';
        if (! $plus && str_starts_with($d, '00')) {
            $d = substr($d, 2);
            $plus = true;
        }
        if (preg_match('/\A01[3-9]\d{8}\z/', $d) === 1) {
            return '+88'.$d;
        }
        if (preg_match('/\A8801[3-9]\d{8}\z/', $d) === 1) {
            return '+'.$d;
        }
        if ($plus && preg_match('/\A[1-9]\d{7,14}\z/', $d) === 1 && ! str_starts_with($d, '880')) {
            return '+'.$d;
        }

        return null;
    }
}
