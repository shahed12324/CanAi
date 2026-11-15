<?php

declare(strict_types=1);

namespace App\Billing;

use Illuminate\Database\QueryException;

/** A billing rule in the database refused the request; `errorCode` is the short machine name it raised. */
final class BillingException extends \RuntimeException
{
    public const CODES = [
        'invalid_currency', 'invalid_interval', 'plan_unavailable', 'price_not_set', 'use_schedule_downgrade', 'package_unavailable',
        'invalid_kind', 'coupon_invalid', 'coupon_exhausted', 'coupon_used', 'invalid_gateway', 'too_many_pending', 'order_not_found',
        'order_closed', 'invalid_reference', 'reference_already_set', 'invalid_transaction', 'no_subscription', 'nothing_to_resume',
        'not_a_downgrade', 'order_not_refundable', 'invalid_amount', 'reason_required', 'not_in_review', 'forbidden',
    ];

    public function __construct(public readonly string $errorCode, ?\Throwable $previous = null)
    {
        parent::__construct('Billing refused: '.$errorCode, 0, $previous);
    }

    public static function fromDatabase(QueryException $e): ?self
    {
        return preg_match('/\b('.implode('|', self::CODES).')\b/', $e->getMessage(), $m) === 1 ? new self($m[1], $e) : null;
    }

    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'coupon_invalid' => 'The coupon code is not correct or cannot be used now.',
            'coupon_exhausted' => 'This coupon’s usage limit is exhausted.',
            'coupon_used' => 'This coupon has already been used in your workspace.',
            'price_not_set', 'plan_unavailable', 'package_unavailable' => 'This plan or package cannot be bought now.',
            'use_schedule_downgrade' => 'To move to the plan below "Schedule downgrade" use; it takes effect when the current term ends.',
            'not_a_downgrade' => 'This is not a downgrade. To upgrade, buy directly.',
            'too_many_pending' => 'Many unfinished payments have piled up. Try again after a while.',
            'no_subscription' => 'You have no active subscription.',
            'nothing_to_resume' => 'There is nothing to restart.',
            'invalid_gateway', 'invalid_currency' => 'This payment method cannot be used for this order.',
            default => 'The request could not be completed.',
        };
    }
}
