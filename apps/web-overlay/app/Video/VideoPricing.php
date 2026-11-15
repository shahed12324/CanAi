<?php

declare(strict_types=1);

namespace App\Video;

use App\Credits\CreditMath;

/** Rendering is charged by the length of the finished video (known before it starts, so the held amount is the final amount).  Rounds UP to 0.0001; never zero.  Pure. */
final class VideoPricing
{
    public function __construct(private readonly string $perSecond) {}

    public static function fromConfig(): self
    {
        return new self((string) config('credits.video_per_second', '0.5'));
    }

    public function forSeconds(float $seconds): string
    {
        $tenths = max(1, (int) round($seconds * 10));
        $u = intdiv(CreditMath::toUnits($this->perSecond) * $tenths + 9, 10);

        return CreditMath::fromUnits(max(1, $u));
    }
}
