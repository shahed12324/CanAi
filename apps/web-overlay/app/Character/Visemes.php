<?php

declare(strict_types=1);

namespace App\Character;

/**
 * Lip-sync TRACK from text and the length of the speech: a list of {t, m} (time in ms from the start, mouth shape from Avatar::MOUTHS).
 * It is an estimate from the letters - not from the audio - so it looks natural but is not frame-exact.
 */
final class Visemes
{
    public const MIN_STEP_MS = 70;

    public const MAX_STEPS = 600;

    private const OPEN = ['া', 'আ', 'অ', 'a', 'A', 'ঁ', 'ং'];

    private const WIDE = ['ি', 'ী', 'ই', 'ঈ', 'ে', 'এ', 'ৈ', 'ঐ', 'e', 'i', 'E', 'I', 'y'];

    private const ROUND = ['ু', 'ূ', 'উ', 'ঊ', 'ো', 'ও', 'ৌ', 'ঔ', 'o', 'u', 'O', 'U', 'w', 'ৃ', 'ঋ'];

    private const LIPS = ['প', 'ফ', 'ব', 'ভ', 'ম', 'p', 'b', 'm', 'f', 'v'];

    /** A rough length of speech for a text when no audio exists yet: 65 ms per character, at least 0.6 s, at most 60 s. */
    public static function estimateMs(string $text): int
    {
        return max(600, min(60000, mb_strlen(trim($text)) * 65));
    }

    /** @return list<array{t: int, m: string}> always starts at t=0 and ends closed at the duration; [] for no speech. */
    public static function timeline(string $text, int $durationMs): array
    {
        $durationMs = max(0, min($durationMs, 600000));
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $shapes = [];
        foreach ($chars as $ch) {
            $m = self::shape($ch);
            if ($m === null) {
                continue;
            }
            if ($m !== end($shapes)) {
                $shapes[] = $m;                                  // one shape per run of the same shape
            }
        }
        if ($durationMs === 0 || $shapes === []) {
            return [];
        }
        $n = count($shapes);
        $step = max(self::MIN_STEP_MS, intdiv($durationMs, $n));
        $keep = max(1, intdiv($durationMs, $step));              // never faster than MIN_STEP_MS
        $keep = min($keep, self::MAX_STEPS);
        if ($n > $keep) {                                        // too many shapes for the time: take evenly spread ones
            $picked = [];
            for ($i = 0; $i < $keep; $i++) {
                $picked[] = $shapes[intdiv($i * $n, $keep)];
            }
            $shapes = $picked;
            $n = $keep;
        }
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = ['t' => intdiv($i * $durationMs, $n), 'm' => $shapes[$i]];
        }
        $out[] = ['t' => $durationMs, 'm' => 'closed'];

        return $out;
    }

    /** Word gaps and punctuation close the mouth; vowels and lip sounds pick a shape; other letters get a half-open mouth. */
    private static function shape(string $ch): ?string
    {
        return match (true) {
            preg_match('/[\s,.;:!?.\-—…]/u', $ch) === 1 => 'closed',
            in_array($ch, self::LIPS, true) => 'closed',
            in_array($ch, self::OPEN, true) => 'wide',
            in_array($ch, self::WIDE, true) => 'smile',
            in_array($ch, self::ROUND, true) => 'round',
            preg_match('/[\p{L}\p{M}]/u', $ch) === 1 => 'open',
            default => null,
        };
    }
}
