<?php

declare(strict_types=1);

namespace App\Social;

/** Reads the adviser's answer defensively.  Pure. */
final class YoutubeSeo
{
    /** @return ?array{title: string, description: string, tags: list<string>, thumbnail_text: string, thumbnail_idea: string} */
    public static function parse(string $raw): ?array
    {
        $j = json_decode(trim($raw), true);
        if (! is_array($j)) {
            $a = strpos($raw, '{');
            $b = strrpos($raw, '}');
            $j = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
        }
        if (! is_array($j) || ! is_string($j['title'] ?? null)) {
            return null;
        }
        $title = YoutubeMeta::title($j['title']);
        $one = fn ($v, int $max) => is_string($v) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1f\x7f<>]/u', ' ', $v))), 0, $max) : '';
        $desc = is_string($j['description'] ?? null) ? mb_substr(YoutubeMeta::description((string) preg_replace('/[ \t]{2,}/u', ' ', (string) preg_replace('~(?:https?://|www\.)\S+~iu', '', $j['description']))), 0, 2000) : '';
        $tags = YoutubeMeta::tags(is_array($j['tags'] ?? null) && array_is_list($j['tags']) ? implode(',', array_filter($j['tags'], 'is_string')) : (is_string($j['tags'] ?? null) ? $j['tags'] : ''));

        return $title === '' ? null : ['title' => $title, 'description' => $desc, 'tags' => $tags, 'thumbnail_text' => $one($j['thumbnail_text'] ?? null, 30), 'thumbnail_idea' => $one($j['thumbnail_idea'] ?? null, 200)];
    }
}
