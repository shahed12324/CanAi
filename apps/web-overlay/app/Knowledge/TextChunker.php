<?php

declare(strict_types=1);

namespace App\Knowledge;

/** Cuts a document into passages of about SIZE characters, at paragraph and sentence borders where possible. */
final class TextChunker
{
    public const SIZE = 800;

    public const MAX_CHUNKS = 600;

    public static function normalize(string $text): string
    {
        $t = str_replace(["\r\n", "\r", "\f", "\0", "\u{FEFF}"], ["\n", "\n", "\n", '', ''], $text);   // pdftotext ends every page with a form feed
        $t = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $t);
        $t = (string) preg_replace('/ ?\n ?/', "\n", $t);
        $t = (string) preg_replace('/\n{3,}/', "\n\n", $t);

        return trim($t);
    }

    /** @return list<string> */
    public static function chunks(string $text, int $size = self::SIZE): array
    {
        $size = max(100, min(1900, $size));
        $pieces = [];
        foreach (preg_split('/\n{2,}/', self::normalize($text)) ?: [] as $para) {
            $para = trim($para);
            if ($para === '') {
                continue;
            }
            if (mb_strlen($para) <= $size) {
                $pieces[] = $para;

                continue;
            }
            // A long paragraph: sentences (Bengali danda included) or lines, then a hard cut for anything still too long.
            foreach (preg_split('/(?<=[।.?!])\s+|\n/u', $para) ?: [] as $s) {
                $s = trim($s);
                while (mb_strlen($s) > $size) {
                    $pieces[] = mb_substr($s, 0, $size);
                    $s = trim(mb_substr($s, $size));
                }
                if ($s !== '') {
                    $pieces[] = $s;
                }
            }
        }
        $out = [];
        $cur = '';
        foreach ($pieces as $p) {
            if ($cur !== '' && mb_strlen($cur) + 1 + mb_strlen($p) > $size) {
                $out[] = $cur;
                $cur = '';
            }
            $cur = $cur === '' ? $p : $cur."\n".$p;
        }
        if ($cur !== '') {
            $out[] = $cur;
        }

        return $out;
    }
}
