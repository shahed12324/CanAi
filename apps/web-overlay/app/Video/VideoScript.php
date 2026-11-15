<?php

declare(strict_types=1);

namespace App\Video;

use App\Office\OfficeSpec;

/** Reads the model's answer into scenes the form can use.  Nothing the model writes is trusted: every field is cleaned and size-limited.  Pure. */
final class VideoScript
{
    /** @return array{title: string, scenes: list<array{headline: string, subline: string, cta: string, seconds: float}>, narration: string}|null */
    public static function parse(string $answer, int $maxScenes): ?array
    {
        $data = json_decode(trim($answer), true);
        if (! is_array($data)) {
            $a = strpos($answer, '{');
            $b = strrpos($answer, '}');
            $data = ($a !== false && $b !== false && $b > $a) ? json_decode(substr($answer, $a, $b - $a + 1), true) : null;
        }
        if (! is_array($data) || array_is_list($data) || ! is_array($data['scenes'] ?? null)) {
            return null;
        }
        $scenes = [];
        $total = 0.0;
        foreach (array_slice(array_values($data['scenes']), 0, max(1, min(VideoService::MAX_SCENES, $maxScenes))) as $s) {
            if (! is_array($s)) {
                continue;
            }
            $h = OfficeSpec::text($s['headline'] ?? '', 200);
            $sub = OfficeSpec::text($s['subline'] ?? '', 400);
            $cta = OfficeSpec::text($s['cta'] ?? '', 100);
            if ($h === '' && $sub === '' && $cta === '') {
                continue;
            }
            $sec = is_numeric($s['seconds'] ?? null) ? max(2.0, min(8.0, round((float) $s['seconds'], 1))) : 4.0;
            if ($total + $sec > VideoService::MAX_TOTAL) {
                $sec = max(2.0, round(VideoService::MAX_TOTAL - $total, 1));
                if ($total + $sec > VideoService::MAX_TOTAL) {
                    break;
                }
            }
            $total += $sec;
            $scenes[] = ['headline' => $h, 'subline' => $sub, 'cta' => $cta, 'seconds' => $sec];
        }
        if ($scenes === []) {
            return null;
        }

        return ['title' => OfficeSpec::text($data['title'] ?? '', 120), 'scenes' => $scenes, 'narration' => OfficeSpec::text($data['narration'] ?? '', 2000)];
    }
}
