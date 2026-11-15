<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Billing\BillingRepo;
use App\Billing\OrderInfo;

/** In-memory stand-in for the billing_* SQL functions (their real behaviour is tested against Postgres in tests/db/test_billing.py). */
final class FakeBillingRepo implements BillingRepo
{
    /** @var array<string, array{tenant: string, gateway: string, currency: string, total: int, item: string, ref: ?string, status: string, txn: ?string}> */
    public array $orders = [];

    public array $settleCalls = [];

    public array $failCalls = [];

    public array $notes = [];

    public int $price = 115000;

    private int $seq = 0;

    public function quote(string $tenantId, string $kind, string $ref, ?string $interval, string $currency, ?string $coupon): array
    {
        return ['total_minor' => $this->price, 'currency' => $currency];
    }

    public function createOrder(string $tenantId, ?string $userId, string $kind, string $ref, ?string $interval, string $currency, ?string $coupon, string $gateway): string
    {
        $id = str_pad(dechex(++$this->seq), 26, '0', STR_PAD_LEFT);
        $this->orders[$id] = ['tenant' => $tenantId, 'gateway' => $gateway, 'currency' => $currency, 'total' => $gateway === 'free' ? 0 : $this->price, 'item' => 'Pro - monthly', 'ref' => null, 'status' => 'pending', 'txn' => null];

        return $id;
    }

    public function order(string $orderId): ?OrderInfo
    {
        $o = $this->orders[$orderId] ?? null;

        return $o === null ? null : new OrderInfo($orderId, $o['tenant'], $o['gateway'], $o['currency'], $o['total'], $o['item'], $o['ref'], $o['status']);
    }

    public function orderTenant(string $orderId): ?string
    {
        return $this->orders[$orderId]['tenant'] ?? null;
    }

    public function orderByRef(string $gateway, string $ref): ?string
    {
        foreach ($this->orders as $id => $o) {
            if ($o['gateway'] === $gateway && $o['ref'] === $ref) {
                return $id;
            }
        }

        return null;
    }

    public function setRef(string $orderId, string $ref): void
    {
        $this->orders[$orderId]['ref'] = $ref;
    }

    public function settle(string $orderId, string $gateway, string $txnId, int $amountMinor, string $currency, ?string $eventId, array $raw): array
    {
        $this->settleCalls[] = compact('orderId', 'gateway', 'txnId', 'amountMinor', 'currency', 'eventId');
        $o = &$this->orders[$orderId];
        if ($o['gateway'] !== $gateway) {
            return ['outcome' => 'gateway_mismatch', 'invoice_id' => null];
        }
        if ($o['status'] === 'paid') {
            return ['outcome' => $o['txn'] === $txnId ? 'duplicate' : 'double_payment', 'invoice_id' => 'inv'];
        }
        if ($currency !== $o['currency']) {
            $o['status'] = 'review';

            return ['outcome' => 'currency_mismatch', 'invoice_id' => null];
        }
        if ($amountMinor !== $o['total']) {
            $o['status'] = 'review';

            return ['outcome' => 'amount_mismatch', 'invoice_id' => null];
        }
        $o['status'] = 'paid';
        $o['txn'] = $txnId;

        return ['outcome' => 'applied', 'invoice_id' => 'inv'];
    }

    public function fail(string $orderId, string $gateway, ?string $eventId, string $outcome, array $raw): string
    {
        $this->failCalls[] = [$orderId, $outcome];
        if ($this->orders[$orderId]['status'] !== 'pending') {
            return 'ignored';
        }
        $this->orders[$orderId]['status'] = $outcome;

        return $outcome;
    }

    public function note(string $orderId, string $gateway, ?string $eventId, array $raw): void
    {
        $this->notes[] = [$orderId, $raw['why'] ?? null];
    }
}
