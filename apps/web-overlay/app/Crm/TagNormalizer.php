<?php

declare(strict_types=1);

namespace App\Crm;

/** "VIP, Dhaka , web design" -> ['vip', 'Dhaka', 'web-design']. Lowercase, letters/digits/-/_ only, 30 chars, at most 10, no repeats. */
final class TagNormalizer
{
    public const MAX_TAGS = 10;

    public const MAX_LENGTH = 30;

    /** @return array{tags: list<string>, truncated: bool} */
    public static function parse(string $input): array
    {
        $tags = [];
        $truncated = false;
        foreach (preg_split('/[,،;\n]+/u', $input) ?: [] as $raw) {
            $t = mb_strtolower(trim($raw));
            $t = preg_replace('/\s+/u', '-', $t) ?? '';
            $t = preg_replace('/[^\p{L}\p{N}\p{M}_-]+/u', '', $t) ?? '';
            $t = trim(mb_substr($t, 0, self::MAX_LENGTH), '-_');
            if ($t === '' || in_array($t, $tags, true)) {
                continue;
            }
            if (count($tags) >= self::MAX_TAGS) {
                $truncated = true;

                continue;
            }
            $tags[] = $t;
        }

        return ['tags' => $tags, 'truncated' => $truncated];
    }
}
