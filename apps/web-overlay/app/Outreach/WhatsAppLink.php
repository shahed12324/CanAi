<?php

declare(strict_types=1);

namespace App\Outreach;

/** A click-to-chat link (https://wa.me/<number>?text=...) built from a phone number; null when the number cannot be a real one. */
final class WhatsAppLink
{
    /** Digits only, with the country code: 01712345678 / +8801712345678 / 8801712345678 / 00 8801712345678 all become 8801712345678. */
    public static function number(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }
        $d = (string) preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }
        if (preg_match('/\A01[3-9][0-9]{8}\z/', $d) === 1) {
            return '88'.$d;                                   // Bangladesh mobile written locally
        }
        if (preg_match('/\A8801[3-9][0-9]{8}\z/', $d) === 1) {
            return $d;
        }

        return preg_match('/\A[1-9][0-9]{9,14}\z/', $d) === 1 && ! str_starts_with($d, '880') ? $d : null;   // other countries: written with their code; a malformed 880... number is refused
    }

    public static function url(?string $phone, string $text = ''): ?string
    {
        $n = self::number($phone);
        if ($n === null) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return 'https://wa.me/'.$n.($text === '' ? '' : '?text='.rawurlencode(mb_substr($text, 0, 200)));
    }
}
