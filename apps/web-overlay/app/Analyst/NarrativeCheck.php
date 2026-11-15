<?php

declare(strict_types=1);

namespace App\Analyst;

/**
 * The guard between the AI's wording and the screen: every number it writes must be one the books gave it, and a forecast must be called an estimate.
 * Anything else and the caller shows the fixed template instead. Pure.
 */
final class NarrativeCheck
{
    /** @param array<string, mixed> $facts @param array<string, mixed> $forecast @return list<float> every number the AI may legitimately quote */
    public static function allowed(array $facts, array $forecast): array
    {
        $out = [];
        self::walk($facts, '', $out);
        self::walk($forecast, '', $out);

        return $out;
    }

    /** @param array<mixed> $a @param list<float> $out */
    private static function walk(array $a, string $key, array &$out): void
    {
        foreach ($a as $k => $v) {
            if (is_array($v)) {
                self::walk($v, (string) $k, $out);

                continue;
            }
            if (! is_int($v) && ! is_float($v)) {
                continue;
            }
            $k = (string) $k;
            if (str_ends_with($k, '_minor')) {
                $out[] = round(abs($v) / 100, 2);
            } elseif (str_ends_with($k, '_pct') || in_array($k, ['runway_months', 'loss_streak', 'history_months', 'based_on_months'], true)) {
                $out[] = round(abs((float) $v), 2);
            }
        }
    }

    /** @return list<float> */
    public static function numbers(string $text): array
    {
        $text = strtr($text, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        preg_match_all('/[0-9]+(?:,[0-9]{2,3})*(?:\.[0-9]+)?/', $text, $m);

        return array_map(fn ($s) => (float) str_replace(',', '', $s), $m[0]);
    }

    /**
     * @param  array<string, mixed>  $facts
     * @param  array<string, mixed>  $forecast
     * @return string ok | empty | unknown_number | unmarked_estimate
     */
    public static function verify(string $text, array $facts, array $forecast): string
    {
        if (mb_strlen(trim($text)) < 20 || mb_strlen($text) > 6000) {
            return 'empty';
        }
        $allowed = self::allowed($facts, $forecast);
        foreach (self::numbers($text) as $n) {
            if (($n == floor($n) && $n <= 12) || ($n >= 2000 && $n <= 2100 && $n == floor($n))) {
                continue;     // small counts ("3 months") and years
            }
            $ok = false;
            foreach ($allowed as $a) {
                if (abs($a - $n) < 0.005) {
                    $ok = true;
                    break;
                }
            }
            if (! $ok) {
                return 'unknown_number';
            }
        }
        if (($forecast['enough'] ?? false) && ! preg_match('/estimated|approximate|আনুমানিক/iu', $text)) {
            return 'unmarked_estimate';
        }

        return 'ok';
    }
}
