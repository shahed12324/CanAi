<?php

declare(strict_types=1);

namespace App\GoogleAds;

/** Reads the Search-ad writer's answer defensively.  Only words come out — never a budget, a link or a claim about money.  Items over the length limits are dropped, not cut.  Pure. */
final class GadsCopy
{
    /** @return ?array{name: string, headlines: list<string>, descriptions: list<string>, keywords: list<array{t: string, m: string}>, negatives: list<string>} */
    public static function parse(string $raw): ?array
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
        $arr = fn ($v) => is_array($v) && array_is_list($v) ? $v : [];
        $h = GadsLines::list($arr($j['headlines'] ?? null), GadsLines::HEADLINES, 30)['items'];
        $d = GadsLines::list($arr($j['descriptions'] ?? null), GadsLines::DESCRIPTIONS, 90)['items'];
        $k = GadsLines::keywords($arr($j['keywords'] ?? null), GadsLines::KEYWORDS)['items'];
        if (count($h) < 3 || count($d) < 2 || count($k) < 1) {
            return null;
        }

        return ['name' => mb_substr(GadsLines::text($j['name'] ?? null), 0, 120), 'headlines' => $h, 'descriptions' => $d, 'keywords' => $k, 'negatives' => GadsLines::list($arr($j['negatives'] ?? null), GadsLines::NEGATIVES, 80)['items']];
    }
}
