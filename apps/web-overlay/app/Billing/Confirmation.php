<?php

declare(strict_types=1);

namespace App\Billing;

/** The verified truth about a payment. status: paid | failed | cancelled | pending | invalid. */
final class Confirmation
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $txnId = null,
        public readonly ?string $eventId = null,
        public readonly ?int $amountMinor = null,
        public readonly ?string $currency = null,
        public readonly array $raw = [],
    ) {}

    public static function invalid(string $why, array $raw = []): self
    {
        return new self('invalid', null, null, null, null, ['why' => $why] + $raw);
    }
}
