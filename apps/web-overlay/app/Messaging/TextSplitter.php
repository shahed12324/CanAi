<?php

declare(strict_types=1);

namespace App\Messaging;

/** Splits a long answer into Telegram-sized messages, preferring paragraph, line, then word boundaries. */
final class TextSplitter
{
    /** @return list<string> never empty, every part 1..$max characters */
    public static function split(string $text, int $max = 3800): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['…'];
        }
        $parts = [];
        while (mb_strlen($text) > $max) {
            $window = mb_substr($text, 0, $max);
            $cut = 0;
            foreach (["\n\n", "\n", ' '] as $sep) {
                $p = mb_strrpos($window, $sep);
                if ($p !== false && $p >= (int) ($max * 0.4)) {
                    $cut = $p;
                    break;
                }
            }
            $cut = $cut === 0 ? $max : $cut;
            $parts[] = rtrim(mb_substr($text, 0, $cut));
            $text = ltrim(mb_substr($text, $cut));
        }
        if ($text !== '') {
            $parts[] = $text;
        }

        return array_values(array_filter($parts, fn ($p) => $p !== '')) ?: ['…'];
    }
}
