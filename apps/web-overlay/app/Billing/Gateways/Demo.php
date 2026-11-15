<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Billing\Confirmation;
use App\Billing\Customer;
use App\Billing\Initiation;
use App\Billing\OrderInfo;
use App\Billing\PaymentGateway;
use App\Billing\Urls;

/**
 * A pretend gateway for demos and for hosts without gateway keys (cPanel demo). No money moves. Switched on ONLY by BILLING_DEMO=true.
 * The "pay" and "fail" buttons carry an HMAC of (order, amount, result), so the page cannot be used to mark an order paid by editing a URL.
 */
final class Demo implements PaymentGateway
{
    public function __construct(private readonly string $key, private readonly \Closure $pageUrl) {}

    public function code(): string
    {
        return 'demo';
    }

    public function label(): string
    {
        return 'Demo payment (no money is charged)';
    }

    public function currencies(): array
    {
        return ['BDT', 'USD'];
    }

    public function sign(string $orderId, int $amountMinor, string $result): string
    {
        return hash_hmac('sha256', $orderId.'|'.$amountMinor.'|'.$result, $this->key);
    }

    public function initiate(OrderInfo $order, Urls $urls, Customer $customer): Initiation
    {
        return new Initiation(($this->pageUrl)($order->id), 'demo_'.$order->id);
    }

    public function locate(array $input, array $headers, string $body): ?array
    {
        $ref = $input['ref'] ?? null;

        return is_string($ref) && preg_match('/\Ademo_[0-9a-z]{26}\z/', $ref) === 1 ? ['order' => null, 'ref' => $ref] : null;
    }

    public function confirm(array $input, array $headers, string $body, OrderInfo $order): Confirmation
    {
        $result = (string) ($input['result'] ?? '');
        $sig = (string) ($input['sig'] ?? '');
        if (($input['ref'] ?? '') !== 'demo_'.$order->id || ! in_array($result, ['paid', 'fail'], true)
            || ! hash_equals($this->sign($order->id, $order->totalMinor, $result), $sig)) {
            return Confirmation::invalid('bad demo signature');
        }
        if ($result === 'fail') {
            return new Confirmation('failed', null, 'demo-fail:'.$order->id, null, null, ['demo' => true]);
        }

        return new Confirmation('paid', 'demo-'.$order->id, 'demo-'.$order->id, $order->totalMinor, $order->currency, ['demo' => true]);
    }
}
