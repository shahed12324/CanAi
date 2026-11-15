<?php

declare(strict_types=1);

namespace App\Billing;

use Closure;

/**
 * The payment flow. Two rules matter above all:
 *   1. A callback is never believed. The gateway is asked (confirm) and only that answer counts.
 *   2. Paying is idempotent and amount-exact; both are enforced again inside the database (billing_settle).
 */
final class PaymentService
{
    /** @param Closure(string, Closure): mixed $inTenant runs a callback with the tenant context (row-level security) switched to that workspace */
    public function __construct(
        private readonly BillingRepo $repo,
        private readonly GatewayRegistry $gateways,
        private readonly Closure $inTenant,
    ) {}

    public function checkout(string $tenantId, ?string $userId, Customer $customer, string $kind, string $ref, ?string $interval, string $currency, ?string $coupon, string $gateway, Urls $urls): CheckoutResult
    {
        $gw = $gateway === 'free' ? null : $this->gateways->get($gateway);
        if ($gw !== null && ! in_array($currency, $gw->currencies(), true)) {
            throw new BillingException('invalid_currency');
        }
        $orderId = $this->repo->createOrder($tenantId, $userId, $kind, $ref, $interval, $currency, $coupon, $gateway);

        if ($gw === null) {                                            // a 100 % coupon: nothing to pay, nothing to verify
            $r = $this->repo->settle($orderId, 'free', 'free:'.$orderId, 0, $currency, 'free:'.$orderId, []);

            return new CheckoutResult($orderId, null, $r['outcome'] === 'applied');
        }

        $order = $this->repo->order($orderId) ?? throw new GatewayFailure('order vanished');
        try {
            $init = $gw->initiate($order, $urls, $customer);
            $this->repo->setRef($orderId, $init->ref);
        } catch (GatewayFailure $e) {
            $this->repo->fail($orderId, $gateway, null, 'failed', ['why' => 'initiate']);
            throw $e;
        }

        return new CheckoutResult($orderId, $init->redirectUrl, false);
    }

    /**
     * Handles a customer return or an IPN/webhook the same way.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $headers
     *
     * @throws GatewayFailure when the gateway could not be asked (the caller should answer 5xx so the gateway retries)
     */
    public function handleCallback(string $gatewayCode, array $input, array $headers, string $body): CallbackResult
    {
        $gw = $this->gateways->find($gatewayCode);
        if ($gw === null) {
            return new CallbackResult('unknown_order');
        }
        $found = $gw->locate($input, $headers, $body);
        if ($found === null) {
            return new CallbackResult('ignored');
        }
        $orderId = $found['order'];
        if ($orderId === null && $found['ref'] !== null) {
            $orderId = $this->repo->orderByRef($gatewayCode, $found['ref']);
        }
        if ($orderId === null || preg_match('/\A[0-9a-z]{26}\z/', $orderId) !== 1) {
            return new CallbackResult('unknown_order');
        }
        $tenant = $this->repo->orderTenant($orderId);
        if ($tenant === null) {
            return new CallbackResult('unknown_order');
        }

        return ($this->inTenant)($tenant, function () use ($gw, $gatewayCode, $orderId, $input, $headers, $body): CallbackResult {
            $order = $this->repo->order($orderId);
            if ($order === null) {
                return new CallbackResult('unknown_order');
            }
            if ($order->gateway !== $gatewayCode) {                    // a callback of one gateway can never settle another gateway's order
                $this->repo->note($orderId, $gatewayCode, null, ['why' => 'gateway_mismatch']);

                return new CallbackResult('gateway_mismatch', $orderId);
            }
            $c = $gw->confirm($input, $headers, $body, $order);

            return match ($c->status) {
                'paid' => $this->settle($order, $c),
                'failed', 'cancelled' => new CallbackResult($this->repo->fail($orderId, $gatewayCode, $c->eventId, $c->status, $c->raw), $orderId),
                'pending' => new CallbackResult('pending', $orderId),
                default => $this->unverified($order, $c),
            };
        });
    }

    private function settle(OrderInfo $order, Confirmation $c): CallbackResult
    {
        if ($c->txnId === null || $c->amountMinor === null || $c->currency === null) {
            return $this->unverified($order, Confirmation::invalid('incomplete confirmation'));
        }
        $r = $this->repo->settle($order->id, $order->gateway, $c->txnId, $c->amountMinor, $c->currency, $c->eventId, $c->raw);

        return new CallbackResult($r['outcome'], $order->id);
    }

    private function unverified(OrderInfo $order, Confirmation $c): CallbackResult
    {
        $this->repo->note($order->id, $order->gateway, $c->eventId, $c->raw);

        return new CallbackResult('unverified', $order->id);
    }
}
