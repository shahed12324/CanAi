<?php

declare(strict_types=1);

namespace App\Chat;

use App\Credits\CreditMath;

/**
 * Tokens -> credits, in integer arithmetic (credits x 10^4). No floats anywhere near money.
 *
 *   credits = ceil( (inTokens/1000 * rateIn + outTokens/1000 * rateOut) * multiplier )
 *
 * rateIn / rateOut are credits per 1,000 tokens at multiplier x1 (config/credits.php); the multiplier is the
 * model's `credit_multiplier` set by the admin. Charging always rounds UP to 0.0001 credit.
 */
final class CreditPricing
{
    /** @param string $rateIn credits per 1K input tokens, e.g. "1";  $rateOut credits per 1K output tokens, e.g. "3" */
    public function __construct(private readonly string $rateIn, private readonly string $rateOut) {}

    public static function fromConfig(): self
    {
        return new self((string) config('credits.rate_input_per_1k', '1'), (string) config('credits.rate_output_per_1k', '3'));
    }

    /** @param string|float|int|null $multiplier model credit_multiplier (null = x1) */
    public function cost(int $inputTokens, int $outputTokens, string|float|int|null $multiplier = null): string
    {
        if ($inputTokens < 0 || $outputTokens < 0) {
            throw new \InvalidArgumentException('Negative token count.');
        }
        $m = self::multiplierUnits($multiplier);
        // rates are credits x 10^4 per 1000 tokens; the multiplier is x 10^4; so divide by 1000 and by 10^4, rounding up.
        $raw = $inputTokens * CreditMath::toUnits($this->rateIn) + $outputTokens * CreditMath::toUnits($this->rateOut);
        $units = intdiv($raw * $m + (1000 * 10000 - 1), 1000 * 10000);

        return CreditMath::fromUnits($units);
    }

    /** Worst case held up-front: the whole prompt plus the largest answer allowed. */
    public function reserveAmount(int $estimatedInput, int $maxOutput, string|float|int|null $multiplier = null): string
    {
        $c = $this->cost($estimatedInput, $maxOutput, $multiplier);

        return CreditMath::isPositive($c) ? $c : '0.0001';   // never reserve zero (a reservation must hold something)
    }

    /** Multiplier as integer x 10^4 (1.5 -> 15000). Accepts what Postgres/PDO hands back (string or float). */
    public static function multiplierUnits(string|float|int|null $m): int
    {
        if ($m === null || $m === '') {
            return 10000;
        }
        $s = is_float($m) ? rtrim(rtrim(number_format($m, 4, '.', ''), '0'), '.') : (string) $m;
        $units = CreditMath::toUnits($s === '' ? '0' : $s);
        if ($units <= 0) {
            throw new \InvalidArgumentException('credit multiplier must be positive');
        }

        return $units;
    }
}
