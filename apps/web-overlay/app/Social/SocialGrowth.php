<?php

declare(strict_types=1);

namespace App\Social;

/**
 * Plain arithmetic over the posts that have numbers: which weekday, time of day, media, caption length and hashtag count did best.  No AI, no guessing.
 * Engagement = reactions + 2 × comments + 3 × shares (comments and shares are rarer and mean more).  Pure.
 */
final class SocialGrowth
{
    public const MIN_POSTS = 5;

    public const MIN_BUCKET = 2;

    public const WEEKDAYS = ['0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday'];

    public const HOURS = ['morning' => 'Morning (6–11)', 'noon' => 'Noon (12–3)', 'evening' => 'Afternoon-evening (4–8)', 'night' => 'Night (9 PM–5 AM)'];

    public const MEDIA = ['none' => 'Text only', 'design' => 'Image/design', 'video' => 'Video'];

    public const LENGTH = ['short' => 'Short (under 150 characters)', 'medium' => 'Medium (150–500)', 'long' => 'Long (over 500)'];

    public const TAGS = ['none' => 'No hashtags', 'few' => '1–3', 'many' => '4 or more'];

    public static function score(int $reactions, int $comments, int $shares): int
    {
        return $reactions + 2 * $comments + 3 * $shares;
    }

    /**
     * @param  list<array{caption: string, media_kind: string, posted_at: string, reactions: ?int, comments: ?int, shares: ?int}>  $rows
     * @return array{n: int, avg: float, enough: bool, groups: array<string, array<string, array{n: int, avg: float}>>, best: array<string, ?string>}
     */
    public static function analyze(array $rows, \DateTimeZone $tz): array
    {
        $g = ['weekday' => [], 'hour' => [], 'media' => [], 'length' => [], 'tags' => []];
        $n = 0;
        $sum = 0;
        foreach ($rows as $r) {
            if ($r['reactions'] === null) {
                continue;
            }
            try {
                $at = (new \DateTimeImmutable($r['posted_at']))->setTimezone($tz);
            } catch (\Throwable) {
                continue;
            }
            $s = self::score((int) $r['reactions'], (int) $r['comments'], (int) $r['shares']);
            $n++;
            $sum += $s;
            $h = (int) $at->format('G');
            $len = mb_strlen((string) $r['caption']);
            $tags = preg_match_all('/#[\p{L}\p{M}\p{N}_]+/u', (string) $r['caption']);
            foreach ([
                'weekday' => $at->format('w'),
                'hour' => $h >= 6 && $h <= 11 ? 'morning' : ($h >= 12 && $h <= 15 ? 'noon' : ($h >= 16 && $h <= 20 ? 'evening' : 'night')),
                'media' => in_array($r['media_kind'], ['design', 'video'], true) ? $r['media_kind'] : 'none',
                'length' => $len < 150 ? 'short' : ($len <= 500 ? 'medium' : 'long'),
                'tags' => $tags === 0 ? 'none' : ($tags <= 3 ? 'few' : 'many'),
            ] as $group => $key) {
                $g[$group][$key]['n'] = ($g[$group][$key]['n'] ?? 0) + 1;
                $g[$group][$key]['sum'] = ($g[$group][$key]['sum'] ?? 0) + $s;
            }
        }
        $enough = $n >= self::MIN_POSTS;
        $best = [];
        foreach ($g as $group => $buckets) {
            $top = null;
            $topAvg = -1.0;
            foreach ($buckets as $key => $b) {
                $avg = round($b['sum'] / $b['n'], 1);
                $g[$group][$key] = ['n' => $b['n'], 'avg' => $avg];
                if ($enough && $b['n'] >= self::MIN_BUCKET && $avg > $topAvg) {
                    $top = (string) $key;
                    $topAvg = $avg;
                }
            }
            ksort($g[$group]);
            // a "best" only counts when at least two buckets can be compared
            $comparable = count(array_filter($g[$group], fn ($b) => $b['n'] >= self::MIN_BUCKET));
            $best[$group] = $comparable >= 2 ? $top : null;
        }

        return ['n' => $n, 'avg' => $n === 0 ? 0.0 : round($sum / $n, 1), 'enough' => $enough, 'groups' => $g, 'best' => $best];
    }
}
