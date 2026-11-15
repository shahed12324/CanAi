<?php

declare(strict_types=1);

namespace App\Social;

/** Reads the drafter's answer defensively and cleans the reply (no links, no control characters, bounded).  Pure. */
final class SocialReplyText
{
    public const MAX = 600;

    /** @return ?array{reply: string, needs_human: bool} null = unreadable */
    public static function parse(string $raw): ?array
    {
        $j = json_decode(trim($raw), true);
        if (! is_array($j)) {
            $a = strpos($raw, '{');
            $b = strrpos($raw, '}');
            $j = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
        }
        if (! is_array($j) || ! is_string($j['reply'] ?? null)) {
            return null;
        }
        $reply = self::clean($j['reply']);
        $human = ($j['needs_human'] ?? false) === true;
        if ($human) {
            return ['reply' => '', 'needs_human' => true];
        }

        return $reply === '' ? null : ['reply' => $reply, 'needs_human' => false];
    }

    public static function clean(string $s): string
    {
        $s = (string) preg_replace('~(?:https?://|www\.)\S+~iu', '', $s);
        $s = (string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $s);
        $s = trim((string) preg_replace('/[ \t]{2,}/u', ' ', $s));

        return mb_substr($s, 0, self::MAX);
    }
}
