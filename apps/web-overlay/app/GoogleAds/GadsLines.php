<?php

declare(strict_types=1);

namespace App\GoogleAds;

/** Shared cleaning of ad words, keywords and one-per-line lists.  Pure. */
final class GadsLines
{
    public const HEADLINES = 15;

    public const DESCRIPTIONS = 4;

    public const KEYWORDS = 30;

    public const NEGATIVES = 30;

    /** One piece of ad text: no tags, no links, single spaces.  Empty when nothing is left. */
    public static function text(mixed $v): string
    {
        if (! is_string($v)) {
            return '';
        }
        $v = (string) preg_replace('/<[^>]*>/u', ' ', $v);
        $v = (string) preg_replace('~(?:https?://|www\.)\S+~iu', ' ', $v);
        $v = (string) preg_replace('/[\x00-\x1f\x7f<>]/u', ' ', $v);

        return trim((string) preg_replace('/\s+/u', ' ', $v));
    }

    /**
     * Cleans a list of texts.  An item longer than $len is DROPPED (never cut in the middle of a word), duplicates (case-insensitive) are dropped, at most $max are kept.
     *
     * @param  list<mixed>  $items
     * @return array{items: list<string>, too_long: int}
     */
    public static function list(array $items, int $max, int $len): array
    {
        $out = [];
        $seen = [];
        $long = 0;
        foreach ($items as $x) {
            $t = self::text($x);
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t) > $len) {
                $long++;

                continue;
            }
            $k = mb_strtolower($t);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $t;
            if (count($out) >= $max) {
                break;
            }
        }

        return ['items' => $out, 'too_long' => $long];
    }

    /**
     * Keywords: [{t, m}] with m = phrase | exact (anything else becomes phrase — broad match is never used).
     *
     * @param  list<mixed>  $items  each a string or ['t' => ..., 'm' => ...]
     * @return array{items: list<array{t: string, m: string}>, too_long: int}
     */
    public static function keywords(array $items, int $max): array
    {
        $out = [];
        $seen = [];
        $long = 0;
        foreach ($items as $x) {
            $m = 'phrase';
            if (is_array($x)) {
                $m = ($x['m'] ?? '') === 'exact' ? 'exact' : 'phrase';
                $x = $x['t'] ?? null;
            }
            $t = self::text($x);
            $t = trim((string) preg_replace('/\A["\[]+|["\]]+\z/u', '', $t));
            if ($t === '') {
                continue;
            }
            if (mb_strlen($t) > 80) {
                $long++;

                continue;
            }
            $k = $m.'|'.mb_strtolower($t);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $out[] = ['t' => $t, 'm' => $m];
            if (count($out) >= $max) {
                break;
            }
        }

        return ['items' => $out, 'too_long' => $long];
    }

    /** Typed text → keyword items: [exact] is exact match, "phrase" or bare text is phrase match. @return list<array{t: string, m: string}>|list<string> raw items for ::keywords() */
    public static function keywordLines(string $block): array
    {
        $items = [];
        foreach (preg_split('/\R/u', $block) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $items[] = ['t' => $line, 'm' => preg_match('/\A\[.*\]\z/u', $line) === 1 ? 'exact' : 'phrase'];
        }

        return $items;
    }

    /** @return list<string> */
    public static function lines(string $block): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $block) ?: []), fn ($l) => $l !== ''));
    }

    /** @param  list<array{t: string, m: string}>  $kw */
    public static function keywordBlock(array $kw): string
    {
        return implode("\n", array_map(fn ($k) => $k['m'] === 'exact' ? '['.$k['t'].']' : $k['t'], $kw));
    }
}
