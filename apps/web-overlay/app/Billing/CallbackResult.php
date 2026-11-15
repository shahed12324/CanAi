<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * outcome: applied | duplicate | double_payment | amount_mismatch | currency_mismatch | gateway_mismatch | duplicate_txn | review |
 *          failed | cancelled | ignored (changed nothing) | pending (gateway says not finished) | unverified | unknown_order
 */
final class CallbackResult
{
    public function __construct(public readonly string $outcome, public readonly ?string $orderId = null) {}

    /** The payment is in and applied (now or earlier). */
    public function isPaid(): bool
    {
        return in_array($this->outcome, ['applied', 'duplicate'], true);
    }

    /** Safe to answer a gateway's IPN with 200: we have dealt with it (even if we refused it). Only transport failures should be retried. */
    public function acknowledge(): bool
    {
        return $this->outcome !== 'unknown_order';
    }
}
