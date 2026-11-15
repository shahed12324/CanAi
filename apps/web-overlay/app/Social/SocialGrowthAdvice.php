<?php

declare(strict_types=1);

namespace App\Social;

/** Reads the adviser's answer defensively.  Pure. */
final class SocialGrowthAdvice
{
    /** @return ?array{summary: string, do: list<string>, avoid: list<string>, test_next: list<string>} */
    public static function parse(string $raw): ?array
    {
        $j = json_decode(trim($raw), true);
        if (! is_array($j)) {
            $a = strpos($raw, '{');
            $b = strrpos($raw, '}');
            $j = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
        }
        if (! is_array($j) || ! is_string($j['summary'] ?? null)) {
            return null;
        }
        $clean = fn ($v) => trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $v));
        $list = function ($v, int $max) use ($clean): array {
            $out = [];
            foreach (is_array($v) && array_is_list($v) ? $v : [] as $x) {
                $t = is_string($x) ? $clean($x) : '';
                if ($t !== '') {
                    $out[] = mb_substr($t, 0, 200);
                }
                if (count($out) === $max) {
                    break;
                }
            }

            return $out;
        };
        $summary = mb_substr($clean($j['summary']), 0, 600);
        $do = $list($j['do'] ?? null, 4);
        $avoid = $list($j['avoid'] ?? null, 3);
        $test = $list($j['test_next'] ?? null, 3);

        return $summary === '' && $do === [] ? null : ['summary' => $summary, 'do' => $do, 'avoid' => $avoid, 'test_next' => $test];
    }
}
