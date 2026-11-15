<?php

declare(strict_types=1);

namespace App\Billing;

use App\Proposals\Money;

/** Display helpers (Bengali labels, money formatting). */
final class Present
{
    public const SUB_STATUS = ['active' => 'Active', 'trialing' => 'Trial', 'past_due' => 'Overdue', 'grace' => 'Grace period', 'cancelled' => 'Cancel', 'expired' => 'Expired'];

    public const ORDER_STATUS = ['pending' => 'Pending', 'paid' => 'Paid', 'failed' => 'Failed', 'cancelled' => 'Cancel', 'expired' => 'Expired', 'review' => 'Verifying', 'partially_refunded' => 'Partial refund', 'refunded' => 'return'];

    public const INTERVAL = ['month' => 'Monthly', 'year' => 'Yearly'];

    public const RESULT = [
        'applied' => ['ok', 'The payment was successful. Thank you!'],
        'duplicate' => ['ok', 'The payment was already accepted successfully.'],
        'failed' => ['warn', 'The payment failed. No money was charged; you can try again.'],
        'cancelled' => ['warn', 'You cancelled the payment.'],
        'pending' => ['warn', 'The payment is not complete yet. It will update by itself when done.'],
        'error' => ['warn', 'The payment could not be verified. Do not worry — it will apply by itself once the gateway confirms. Check here after a while.'],
        'unverified' => ['warn', 'The payment could not be verified, so it was not applied. If money was deducted, contact support.'],
        'review' => ['warn', 'The payment is awaiting verification. If money was deducted we will quickly reconcile it.'],
        'amount_mismatch' => ['warn', 'The amount paid did not match the order. It is under review; if money was deducted, contact support.'],
        'currency_mismatch' => ['warn', 'The payment currency did not match the order. It is under review.'],
        'double_payment' => ['warn', 'Money was deducted more than once for this order. We will get in touch to refund the extra part.'],
    ];

    /** "৳ ১,১৫০" for BDT, "USD 10.00" otherwise. */
    public static function money(int $minor, string $currency): string
    {
        return trim($currency) === 'BDT' ? Money::format($minor, 'bn') : trim($currency).' '.Amount::toDecimal($minor);
    }

    public static function bp(int $bp): string
    {
        return rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.').'%';
    }
}
