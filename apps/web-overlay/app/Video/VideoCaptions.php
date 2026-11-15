<?php

declare(strict_types=1);

namespace App\Video;

/** Cuts a voice-over script into subtitle lines (at most 40, each at most 120 characters).  The engine spreads them over the voice length.  Pure. */
final class VideoCaptions
{
    public const MAX_LINES = 40;

    public const MAX_CHARS = 120;

    /** @return list<string> */
    public static function chunks(string $text): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return [];
        }
        $pieces = [];
        foreach ((array) preg_split('/(?<=[।.!?‼؟])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $sentence) {
            foreach (self::split((string) $sentence, self::MAX_CHARS) as $p) {
                $pieces[] = $p;
            }
        }
        foreach ([60, 80, 100, self::MAX_CHARS] as $limit) {
            $out = self::merge($pieces, $limit);
            if (count($out) <= self::MAX_LINES) {
                return $out;
            }
        }

        return array_slice($out, 0, self::MAX_LINES);
    }

    /** @return list<string> a sentence cut at spaces into parts of at most $max characters (a word longer than $max is cut hard) */
    private static function split(string $s, int $max): array
    {
        if (mb_strlen($s) <= $max) {
            return [$s];
        }
        $parts = [];
        $cur = '';
        foreach (explode(' ', $s) as $w) {
            while (mb_strlen($w) > $max) {
                if ($cur !== '') {
                    $parts[] = $cur;
                    $cur = '';
                }
                $parts[] = mb_substr($w, 0, $max);
                $w = mb_substr($w, $max);
            }
            $trial = $cur === '' ? $w : $cur.' '.$w;
            if (mb_strlen($trial) > $max) {
                $parts[] = $cur;
                $cur = $w;
            } else {
                $cur = $trial;
            }
        }
        if ($cur !== '') {
            $parts[] = $cur;
        }

        return $parts;
    }

    /**
     * @param  list<string>  $pieces
     * @return list<string>
     */
    private static function merge(array $pieces, int $limit): array
    {
        $out = [];
        $cur = '';
        foreach ($pieces as $p) {
            $trial = $cur === '' ? $p : $cur.' '.$p;
            if ($cur !== '' && mb_strlen($trial) > $limit) {
                $out[] = $cur;
                $cur = $p;
            } else {
                $cur = $trial;
            }
        }
        if ($cur !== '') {
            $out[] = $cur;
        }

        return $out;
    }
}
