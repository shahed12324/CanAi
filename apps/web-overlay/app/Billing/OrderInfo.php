<?php

declare(strict_types=1);

namespace App\Billing;

/** What a gateway needs to know about an order. */
final class OrderInfo
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $gateway,
        public readonly string $currency,
        public readonly int $totalMinor,
        public readonly string $itemName,
        public readonly ?string $gatewayRef,
        public readonly string $status,
    ) {}
}
