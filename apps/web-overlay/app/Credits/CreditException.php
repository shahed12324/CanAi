<?php

declare(strict_types=1);

namespace App\Credits;

use Illuminate\Database\QueryException;
use RuntimeException;

/** A money rule refused the operation. `code` is the stable machine name raised by the SQL function. */
final class CreditException extends RuntimeException
{
    public const CODES = [
        'insufficient_credits', 'spending_limit_reached', 'invalid_amount', 'invalid_ttl', 'invalid_type',
        'idempotency_key_required', 'idempotency_conflict', 'reference_required', 'reason_required',
        'reservation_not_found', 'reservation_not_open', 'already_expired', 'forbidden', 'lots_inconsistent',
    ];

    public function __construct(public readonly string $errorCode, ?\Throwable $previous = null)
    {
        parent::__construct('Credit operation refused: '.$errorCode, 0, $previous);
    }

    /** Returns a CreditException for a known money-rule error, or null (caller rethrows the original). */
    public static function fromDatabase(QueryException $e): ?self
    {
        if (preg_match('/\b('.implode('|', self::CODES).')\b/', $e->getMessage(), $m) === 1) {
            return new self($m[1], $e);
        }

        return null;
    }

    /** Bengali message that is safe to show a customer. */
    public function userMessage(): string
    {
        return match ($this->errorCode) {
            'insufficient_credits' => 'Not enough credits. Add credits.',
            'spending_limit_reached' => 'This month’s spending limit is full.',
            'idempotency_conflict' => 'The same request key was used with different data.',
            default => 'The credit operation could not be completed.',
        };
    }
}
