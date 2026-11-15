<?php

declare(strict_types=1);

namespace App\Messaging;

/** One-time codes that connect a Telegram identity to a signed-in user. 8 chars of a confusion-free alphabet (~40 bits). */
final class LinkCode
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';   // no I, L, O, 0, 1

    public static function generate(): string
    {
        $s = '';
        for ($i = 0; $i < 8; $i++) {
            $s .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $s;
    }

    /** Accepts "abcd-efgh", " ABCDEFGH " etc. Returns '' when it cannot be a code. */
    public static function normalize(string $input): string
    {
        $c = strtoupper((string) preg_replace('/[\s\-]+/', '', $input));

        return preg_match('/\A['.self::ALPHABET.']{8}\z/', $c) === 1 ? $c : '';
    }

    public static function hash(string $normalized): string
    {
        return hash('sha256', $normalized);
    }

    public static function pretty(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }
}
