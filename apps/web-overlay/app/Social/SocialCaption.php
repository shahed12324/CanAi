<?php

declare(strict_types=1);

namespace App\Social;

/** Reads the model's answer defensively: {caption, hashtags[]} or nothing.  Pure. */
final class SocialCaption
{
    /** @return ?array{caption: string, hashtags: list<string>} */
    public static function parse(string $raw, string $platform): ?array
    {
        $j = json_decode(trim($raw), true);
        if (! is_array($j)) {
            $a = strpos($raw, '{');
            $b = strrpos($raw, '}');
            $j = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
        }
        if (! is_array($j)) {
            return null;
        }
        $caption = $j['caption'] ?? null;
        if (! is_string($caption)) {
            return null;
        }
        $caption = trim((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', str_replace("\r\n", "\n", $caption)));
        if ($caption === '') {
            return null;
        }
        $tags = [];
        foreach (is_array($j['hashtags'] ?? null) ? $j['hashtags'] : [] as $t) {
            if (! is_string($t)) {
                continue;
            }
            $t = (string) preg_replace('/[^\p{L}\p{M}\p{N}_]+/u', '', ltrim(trim($t), '#'));
            if ($t !== '' && mb_strlen($t) <= 40 && ! in_array($t, $tags, true)) {
                $tags[] = $t;
            }
            if (count($tags) === 8) {
                break;
            }
        }
        $cap = min(3000, SocialText::LIMITS[$platform] ?? 2200);
        $tagLine = $tags === [] ? '' : "\n\n".implode(' ', array_map(fn ($t) => '#'.$t, $tags));
        while ($tags !== [] && mb_strlen($caption.$tagLine) > $cap) {
            array_pop($tags);
            $tagLine = $tags === [] ? '' : "\n\n".implode(' ', array_map(fn ($t) => '#'.$t, $tags));
        }

        return ['caption' => mb_substr($caption.$tagLine, 0, $cap), 'hashtags' => $tags];
    }
}
