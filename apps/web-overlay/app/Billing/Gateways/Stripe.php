<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Billing\Confirmation;
use App\Billing\Customer;
use App\Billing\GatewayFailure;
use App\Billing\Initiation;
use App\Billing\OrderInfo;
use App\Billing\PaymentGateway;
use App\Billing\Urls;
use App\Outreach\HttpJson;

/**
 * Stripe Checkout. Webhooks are signature-checked (HMAC-SHA256 over "t.payload", 5 minute tolerance) and then, like the customer
 * return, confirmed by fetching the Checkout Session from Stripe. Not exercised against Stripe's test mode in this repository's tests.
 */
final class Stripe implements PaymentGateway
{
    /** @param list<string> $currencies */
    public function __construct(
        private readonly HttpJson $http,
        private readonly string $secretKey,
        private readonly string $webhookSecret,
        private readonly array $currencies = ['USD'],
        private readonly ?\Closure $now = null,
    ) {}

    public function code(): string
    {
        return 'stripe';
    }

    public function label(): string
    {
        return 'Stripe (International card)';
    }

    public function currencies(): array
    {
        return $this->currencies;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->secretKey];
    }

    public function initiate(OrderInfo $order, Urls $urls, Customer $customer): Initiation
    {
        try {
            $res = $this->http->request('POST', 'https://api.stripe.com/v1/checkout/sessions', $this->auth(), [
                'mode' => 'payment', 'client_reference_id' => $order->id, 'customer_email' => $customer->email,
                'success_url' => $urls->return.(str_contains($urls->return, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $urls->cancel,
                'line_items[0][quantity]' => '1',
                'line_items[0][price_data][currency]' => strtolower($order->currency),
                'line_items[0][price_data][unit_amount]' => (string) $order->totalMinor,
                'line_items[0][price_data][product_data][name]' => mb_substr($order->itemName, 0, 200),
                'metadata[order_id]' => $order->id,
            ]);
        } catch (\Throwable) {
            throw new GatewayFailure('stripe unreachable');
        }
        $id = $res['json']['id'] ?? null;
        $url = $res['json']['url'] ?? null;
        if ($res['status'] !== 200 || ! is_string($id) || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new GatewayFailure('stripe refused the session');
        }

        return new Initiation($url, $id);
    }

    public function locate(array $input, array $headers, string $body): ?array
    {
        $sig = $headers['stripe-signature'] ?? null;
        if ($sig !== null) {                                            // a webhook
            if (! $this->signatureOk($sig, $body)) {
                throw new GatewayFailure('stripe webhook signature is wrong');
            }
            $event = json_decode($body, true);
            $obj = is_array($event) ? ($event['data']['object'] ?? null) : null;
            if (! is_array($obj) || ! in_array($event['type'] ?? '', ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'], true)) {
                return null;
            }
            $id = $obj['id'] ?? null;

            return is_string($id) && str_starts_with($id, 'cs_') ? ['order' => null, 'ref' => $id] : null;
        }
        $id = $input['session_id'] ?? null;                              // the customer coming back

        return is_string($id) && preg_match('/\Acs_[A-Za-z0-9_]{1,200}\z/', $id) === 1 ? ['order' => null, 'ref' => $id] : null;
    }

    public function confirm(array $input, array $headers, string $body, OrderInfo $order): Confirmation
    {
        $id = (string) $order->gatewayRef;
        if ($id === '') {
            return Confirmation::invalid('order has no session');
        }
        try {
            $res = $this->http->request('GET', 'https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($id), $this->auth());
        } catch (\Throwable) {
            throw new GatewayFailure('stripe unreachable');
        }
        if ($res['status'] >= 500) {
            throw new GatewayFailure('stripe http '.$res['status']);
        }
        $s = $res['json'];
        $raw = ['session' => $id, 'payment_status' => $s['payment_status'] ?? null, 'status' => $s['status'] ?? null];
        if ($res['status'] !== 200 || ($s['id'] ?? null) !== $id) {
            return Confirmation::invalid('session not found', $raw);
        }
        if (($s['client_reference_id'] ?? null) !== $order->id) {
            return Confirmation::invalid('session belongs to another order', $raw);
        }
        if (($s['payment_status'] ?? '') === 'paid') {
            $pi = $s['payment_intent'] ?? null;
            if (! is_string($pi) || $pi === '' || ! is_int($s['amount_total'] ?? null)) {
                return Confirmation::invalid('no payment intent or amount', $raw);
            }

            return new Confirmation('paid', $pi, $id, $s['amount_total'], strtoupper((string) ($s['currency'] ?? '')), $raw);
        }

        return match ($s['status'] ?? '') {
            'expired' => new Confirmation('cancelled', null, 'expired:'.$id, null, null, $raw),
            default => new Confirmation('pending', null, null, null, null, $raw),
        };
    }

    private function signatureOk(string $header, string $body): bool
    {
        $t = null;
        $v1 = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = $v;
            } elseif ($k === 'v1') {
                $v1[] = $v;
            }
        }
        if ($t === null || ! ctype_digit($t) || $v1 === []) {
            return false;
        }
        $now = $this->now !== null ? ($this->now)() : time();
        if (abs($now - (int) $t) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', $t.'.'.$body, $this->webhookSecret);
        foreach ($v1 as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
