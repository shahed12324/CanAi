<?php

declare(strict_types=1);

namespace App\Memory;

/**
 * What may be remembered. Mirrors the database function memory_check_text() (the database is the real enforcer; this gives a friendly message first and
 * filters the AI's suggestions). A parity test runs the same strings through both. Pure.
 */
final class MemoryGuard
{
    public const CATEGORIES = ['preference' => 'Preference', 'fact' => 'Fact', 'business' => 'Business', 'person' => 'Person', 'other' => 'Other'];

    public const MAX = 500;

    /** @return string ok | empty | too_long | sensitive */
    public static function check(?string $text): string
    {
        $t = trim((string) $text);
        if ($t === '') {
            return 'empty';
        }
        if (mb_strlen($t) > self::MAX) {
            return 'too_long';
        }
        $n = strtr($t, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        if (preg_match('/(password|passwd|passcode|\bpwd\b|\botp\b|\bcvv\b|\bcvc\b|secret|api[ _-]?key|private[ _-]?key|access[ _-]?token|\btoken\b|\bpin\b|পাসওয়ার্ড|পাসকোড|পিন নম্বর|পিন নাম্বার|ওটিপি|সিক্রেট|টোকেন)/iu', $n) === 1
            || preg_match('/[0-9]([ -]?[0-9]){12,}/', $n) === 1
            || preg_match('/(^|[^A-Za-z0-9])(sk|pk|rk|AKIA|AIza|ghp|gho|xox[abp])[-_A-Za-z0-9]{16,}/', $n) === 1
            || preg_match('/[A-Za-z0-9_-]{32,}/', $n) === 1) {
            return 'sensitive';
        }

        return 'ok';
    }

    /** Same cleaning as the database: outer spaces off, inner whitespace runs become one space. */
    public static function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', trim($text)));
    }
}
