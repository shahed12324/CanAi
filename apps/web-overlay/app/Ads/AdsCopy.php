<?php

declare(strict_types=1);

namespace App\Ads;

/** Reads the ad writer's answer defensively.  Only words and a starting audience come out — never a budget, a link or a claim about money.  Pure. */
final class AdsCopy
{
    /** @return ?array{name: string, primary_text: string, headline: string, description: string, cta: string, age_min: int, age_max: int, gender: string, interests: string} */
    public static function parse(string $raw, string $objective): ?array
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
        $one = fn ($v, int $max) => is_string($v) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('~(?:https?://|www\.)\S+~iu', '', (string) preg_replace('/[\x00-\x1f\x7f<>]/u', ' ', (string) preg_replace('/<[^>]*>/u', '', $v))))), 0, $max) : '';
        $headline = $one($j['headline'] ?? null, 80);
        $text = is_string($j['primary_text'] ?? null) ? mb_substr(trim((string) preg_replace('/[^\S\n]{2,}/u', ' ', (string) preg_replace('~(?:https?://|www\.)\S+~iu', '', (string) preg_replace('/[\x00-\x09\x0b-\x1f\x7f<>]/u', ' ', (string) preg_replace('/<[^>]*>/u', '', $j['primary_text']))))), 0, 1000) : '';
        if ($headline === '' || $text === '') {
            return null;
        }
        $n = fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
        $min = $n($j['age_min'] ?? null) ?? 18;
        $max = $n($j['age_max'] ?? null) ?? 65;
        $min = max(18, min(65, $min));
        $max = max(18, min(65, $max));
        if ($min > $max) {
            [$min, $max] = [18, 65];
        }
        $cta = is_string($j['cta'] ?? null) && isset(AdsText::CTAS[$j['cta']]) ? $j['cta'] : self::defaultCta($objective);
        $gender = is_string($j['gender'] ?? null) && isset(AdsText::GENDERS[$j['gender']]) ? $j['gender'] : 'all';
        $interests = is_array($j['interests'] ?? null) && array_is_list($j['interests']) ? implode(', ', array_filter(array_map(fn ($x) => is_string($x) ? $one($x, 40) : '', array_slice($j['interests'], 0, 10)), fn ($x) => $x !== '')) : $one($j['interests'] ?? null, 300);

        return ['name' => $one($j['name'] ?? null, 120), 'primary_text' => $text, 'headline' => $headline, 'description' => $one($j['description'] ?? null, 200), 'cta' => $cta, 'age_min' => $min, 'age_max' => $max, 'gender' => $gender, 'interests' => mb_substr($interests, 0, 300)];
    }

    public static function defaultCta(string $objective): string
    {
        return ['sales' => 'SHOP_NOW', 'leads' => 'SIGN_UP', 'messages' => 'SEND_MESSAGE', 'traffic' => 'LEARN_MORE', 'awareness' => 'LEARN_MORE'][$objective] ?? 'LEARN_MORE';
    }
}
