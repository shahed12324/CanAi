<?php

declare(strict_types=1);

namespace App\Social;

/** Reads the model's plan defensively into clean, bounded draft proposals.  Pure. */
final class SocialPlan
{
    /**
     * @param  list<string>  $platforms  the platforms the person asked for
     * @return list<array{title: string, platform: string, caption: string, scheduled: string, media: string, idea: string}>|null null when nothing usable
     */
    public static function parse(string $raw, array $platforms, int $days, string $startDate, int $count): ?array
    {
        $j = json_decode(trim($raw), true);
        if (! is_array($j)) {
            $a = strpos($raw, '{');
            $b = strrpos($raw, '}');
            $j = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($raw, $a, $b - $a + 1), true) : null;
        }
        $posts = is_array($j) && is_array($j['posts'] ?? null) && array_is_list($j['posts']) ? $j['posts'] : null;
        if ($posts === null) {
            return null;
        }
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate);
        if ($start === false) {
            return null;
        }
        $out = [];
        $seen = [];
        foreach ($posts as $p) {
            if (! is_array($p) || count($out) >= min(SocialPlanPrompt::MAX_POSTS, max(1, $count))) {
                continue;
            }
            $platform = is_string($p['platform'] ?? null) ? $p['platform'] : '';
            $captionText = is_string($p['caption'] ?? null) ? $p['caption'] : '';
            if (! in_array($platform, $platforms, true) || trim($captionText) === '') {
                continue;
            }
            $cap = SocialCaption::parse(json_encode(['caption' => $captionText, 'hashtags' => is_array($p['hashtags'] ?? null) ? $p['hashtags'] : []]), $platform);
            if ($cap === null) {
                continue;
            }
            $title = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', is_string($p['title'] ?? null) ? $p['title'] : ''));
            $title = mb_substr($title === '' ? mb_substr($cap['caption'], 0, 60) : $title, 0, 80);
            $key = mb_strtolower($title.'|'.$platform);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $day = is_numeric($p['day'] ?? null) ? max(0, min($days - 1, (int) $p['day'])) : 0;
            $time = is_string($p['time'] ?? null) && preg_match('/\A([01]?\d|2[0-3]):([0-5]\d)\z/', trim($p['time']), $m) === 1 ? sprintf('%02d:%s', max(7, min(22, (int) $m[1])), (int) $m[1] > 22 || (int) $m[1] < 7 ? '00' : $m[2]) : '10:00';
            $media = in_array($p['media'] ?? null, ['design', 'video'], true) ? $p['media'] : 'none';
            $idea = trim((string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', is_string($p['media_idea'] ?? null) ? $p['media_idea'] : ''));
            $out[] = ['title' => $title, 'platform' => $platform, 'caption' => $cap['caption'], 'scheduled' => $start->modify("+{$day} days")->format('Y-m-d').'T'.$time, 'media' => $media, 'idea' => mb_substr($idea, 0, 300)];
        }
        usort($out, fn ($a, $b) => strcmp($a['scheduled'], $b['scheduled']));

        return $out === [] ? null : $out;
    }
}
