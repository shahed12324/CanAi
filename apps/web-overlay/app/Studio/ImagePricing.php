<?php

declare(strict_types=1);

namespace App\Studio;

use App\Chat\CreditPricing;
use App\Credits\CreditMath;

/** A picture costs a flat amount (known before the call, so the held amount is the final amount); the model's credit_multiplier applies; rounds UP; never zero. Pure. */
final class ImagePricing
{
    public function __construct(private readonly string $perImage) {}

    public static function fromConfig(): self
    {
        return new self((string) config('credits.image_per_image', '5'));
    }

    public function image(int $count = 1, string|float|int|null $multiplier = null): string
    {
        $m = CreditPricing::multiplierUnits($multiplier);
        $u = intdiv(CreditMath::toUnits($this->perImage) * max(1, $count) * $m + 9999, 10000);

        return CreditMath::fromUnits(max(1, $u));
    }
}
