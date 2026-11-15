<?php

declare(strict_types=1);

namespace App\Voice;

use App\Chat\CreditPricing;
use App\Credits\CreditMath;

/**
 * Speech is charged by size, not tokens: listening per megabyte of audio, speaking per 1,000 characters.  Both are known before the call, so the held amount
 * is the final amount.  Always rounds UP to 0.0001; the model's credit_multiplier applies.  Pure.
 */
final class VoicePricing
{
    public function __construct(private readonly string $listenPerMb, private readonly string $speakPer1k) {}

    public static function fromConfig(): self
    {
        return new self((string) config('credits.voice_listen_per_mb', '1'), (string) config('credits.voice_speak_per_1k_chars', '1'));
    }

    public function listen(int $bytes, string|float|int|null $multiplier = null): string
    {
        return self::units(CreditMath::toUnits($this->listenPerMb) * max(0, $bytes), 1_000_000, $multiplier);
    }

    public function speak(int $chars, string|float|int|null $multiplier = null): string
    {
        return self::units(CreditMath::toUnits($this->speakPer1k) * max(0, $chars), 1000, $multiplier);
    }

    private static function units(int $raw, int $per, string|float|int|null $multiplier): string
    {
        $m = CreditPricing::multiplierUnits($multiplier);
        $u = intdiv($raw * $m + ($per * 10000 - 1), $per * 10000);

        return CreditMath::fromUnits(max(1, $u));      // never free, never zero (a reservation must hold something)
    }
}
