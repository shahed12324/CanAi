<?php

declare(strict_types=1);

namespace App\Billing;

/** Everything the payment flow needs from storage. All money rules are enforced by the database functions behind it. */
interface BillingRepo
{
    /** @return array<string, mixed> the quote row (list_minor, proration_minor, discount_minor, vat_bp, vat_minor, total_minor, change_kind, item_name, ...) */
    public function quote(string $tenantId, string $kind, string $ref, ?string $interval, string $currency, ?string $coupon): array;

    public function createOrder(string $tenantId, ?string $userId, string $kind, string $ref, ?string $interval, string $currency, ?string $coupon, string $gateway): string;

    public function order(string $orderId): ?OrderInfo;

    public function orderTenant(string $orderId): ?string;

    public function orderByRef(string $gateway, string $ref): ?string;

    public function setRef(string $orderId, string $ref): void;

    /** @return array{outcome: string, invoice_id: ?string} */
    public function settle(string $orderId, string $gateway, string $txnId, int $amountMinor, string $currency, ?string $eventId, array $raw): array;

    public function fail(string $orderId, string $gateway, ?string $eventId, string $outcome, array $raw): string;

    public function note(string $orderId, string $gateway, ?string $eventId, array $raw): void;
}
