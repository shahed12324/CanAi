<?php

declare(strict_types=1);

namespace App\Chat;

/**
 * Cheap, deliberately pessimistic token estimate used only when a provider does not report usage
 * and to size the up-front reservation. ASCII ~4 chars/token; everything else (Bengali etc.) ~1.5 chars/token.
 */
final class TokenEstimator
{
    public static function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }
        $nonAscii = preg_match_all('/[^\x00-\x7F]/u', $text);
        $total = mb_strlen($text);
        $nonAscii = $nonAscii === false ? $total : $nonAscii;

        return (int) ceil(($total - $nonAscii) / 4 + $nonAscii / 1.5);
    }
}
