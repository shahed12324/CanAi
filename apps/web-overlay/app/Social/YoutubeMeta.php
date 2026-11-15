<?php

declare(strict_types=1);

namespace App\Social;

/** Cleans what is sent to YouTube (title, description, tags) and says what a post still lacks before it can be approved.  Pure. */
final class YoutubeMeta
{
    public const TITLE_MAX = 100;

    public const DESC_MAX = 3000;

    public const TAGS_MAX = 15;

    public const TAG_LEN = 30;

    public const TAGS_CHARS = 450;

    public const PRIVACY = ['private' => 'Private (only you will see it)', 'unlisted' => 'Unlisted (anyone with the link will see it)', 'public' => 'Public (everyone will see it)'];

    public static function title(string $s): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', str_replace(['<', '>'], '', $s))), 0, self::TITLE_MAX);
    }

    public static function description(string $s): string
    {
        $s = str_replace(['<', '>'], '', str_replace("\r\n", "\n", $s));

        return trim(mb_substr((string) preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $s), 0, self::DESC_MAX));
    }

    /** @return list<string> */
    public static function tags(string $s): array
    {
        $out = [];
        $seen = [];
        $chars = 0;
        foreach (preg_split('/[,\n]+/u', str_replace(['<', '>', '"'], '', $s)) ?: [] as $t) {
            $t = mb_substr(trim((string) preg_replace('/\s+/u', ' ', ltrim(trim($t), '#'))), 0, self::TAG_LEN);
            $k = mb_strtolower($t);
            if ($t === '' || isset($seen[$k]) || $chars + mb_strlen($t) > self::TAGS_CHARS) {
                continue;
            }
            $seen[$k] = true;
            $out[] = $t;
            $chars += mb_strlen($t);
            if (count($out) === self::TAGS_MAX) {
                break;
            }
        }

        return $out;
    }

    /**
     * What stops this post from being uploaded.  Empty = ready.
     *
     * @param  array{platform: string, title: string, media_kind: string, account_id: ?string}  $p
     * @return list<string>
     */
    public static function problems(array $p): array
    {
        if ($p['platform'] !== 'youtube') {
            return [];
        }
        $out = [];
        if ($p['media_kind'] !== 'video') {
            $out[] = 'YouTube A created video is needed in the post.';
        }
        if ($p['account_id'] === null) {
            $out[] = 'YouTube The channel was not chosen (see YouTube settings).';
        }
        if (mb_strlen($p['title']) > self::TITLE_MAX) {
            $out[] = 'YouTube The title is at most 100 characters — shorten the name.';
        }

        return $out;
    }
}
