<?php

declare(strict_types=1);

namespace App\Billing\Gateways;

use App\Billing\Amount;
use App\Billing\Confirmation;
use App\Billing\Customer;
use App\Billing\GatewayFailure;
use App\Billing\Initiation;
use App\Billing\OrderInfo;
use App\Billing\PaymentGateway;
use App\Billing\Urls;
use App\Outreach\HttpJson;

/**
 * Nagad (initialize -> complete -> customer pays -> callback -> verify). Every request is RSA-encrypted for Nagad and signed with the
 * merchant key; the final truth is Nagad's "verify payment" answer. Written from the public integration guide; not exercised against
 * Nagad's sandbox here. NOTE: the sandbox host is plain http; this app only talks https, so use the live host (or an https proxy
 * you control) via BILLING_NAGAD_BASE.
 */
final class Nagad implements PaymentGateway
{
    public function __construct(
        private readonly HttpJson $http,
        private readonly string $merchantId,
        private readonly Rsa $rsa,
        private readonly string $baseUrl = 'https://api.mynagad.com/api/dfs',
        private readonly ?\Closure $now = null,          // fn(): string  Ymdhis in Asia/Dhaka
        private readonly ?\Closure $challenge = null,    // fn(): string  random 40 chars
        private readonly string $clientIp = '127.0.0.1',
    ) {}

    public function code(): string
    {
        return 'nagad';
    }

    public function label(): string
    {
        return 'Nagad';
    }

    public function currencies(): array
    {
        return ['BDT'];
    }

    /** Nagad accepts a short alphanumeric merchant order id; we send the first 20 characters of ours and look the order up by payment reference. */
    public static function shortId(string $orderId): string
    {
        return substr($orderId, 0, 20);
    }

    private function headers(): array
    {
        return ['Content-Type' => 'application/json', 'X-KM-Api-Version' => 'v-0.2.0', 'X-KM-IP-V4' => $this->clientIp, 'X-KM-Client-Type' => 'PC_WEB'];
    }

    private function stamp(): string
    {
        return $this->now !== null ? ($this->now)() : (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Dhaka')))->format('YmdHis');
    }

    /** @return array<string, mixed> */
    private function post(string $url, array $payload): array
    {
        try {
            $res = $this->http->request('POST', $url, $this->headers(), null, json_encode($payload, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new GatewayFailure('nagad unreachable');
        }
        if ($res['status'] >= 500) {
            throw new GatewayFailure('nagad http '.$res['status']);
        }

        return $res['json'];
    }

    public function initiate(OrderInfo $order, Urls $urls, Customer $customer): Initiation
    {
        $short = self::shortId($order->id);
        $challenge = $this->challenge !== null ? ($this->challenge)() : bin2hex(random_bytes(20));
        $plain = json_encode(['merchantId' => $this->merchantId, 'datetime' => $this->stamp(), 'orderId' => $short, 'challenge' => $challenge], JSON_THROW_ON_ERROR);
        $init = $this->post(rtrim($this->baseUrl, '/').'/check-out/initialize/'.$this->merchantId.'/'.$short, [
            'dateTime' => $this->stamp(), 'sensitiveData' => $this->rsa->encryptForPeer($plain), 'signature' => $this->rsa->sign($plain),
        ]);
        if (! is_string($init['sensitiveData'] ?? null)) {
            throw new GatewayFailure('nagad refused to initialize');
        }
        $reply = json_decode($this->rsa->decrypt($init['sensitiveData']), true);
        $ref = is_array($reply) ? ($reply['paymentReferenceId'] ?? null) : null;
        $back = is_array($reply) ? ($reply['challenge'] ?? null) : null;
        if (! is_string($ref) || $ref === '' || ! is_string($back) || $back === '') {
            throw new GatewayFailure('nagad initialize answer is incomplete');
        }

        $plain2 = json_encode(['merchantId' => $this->merchantId, 'orderId' => $short, 'currencyCode' => '050', 'amount' => Amount::toDecimal($order->totalMinor), 'challenge' => $back], JSON_THROW_ON_ERROR);
        $done = $this->post(rtrim($this->baseUrl, '/').'/check-out/complete/'.rawurlencode($ref), [
            'sensitiveData' => $this->rsa->encryptForPeer($plain2), 'signature' => $this->rsa->sign($plain2),
            'merchantCallbackURL' => $urls->return, 'additionalMerchantInfo' => ['item' => mb_substr($order->itemName, 0, 60)],
        ]);
        $url = $done['callBackUrl'] ?? null;
        if (($done['status'] ?? '') !== 'Success' || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new GatewayFailure('nagad refused to complete');
        }

        return new Initiation($url, $ref);
    }

    public function locate(array $input, array $headers, string $body): ?array
    {
        $ref = $input['payment_ref_id'] ?? null;

        return is_string($ref) && preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $ref) === 1 ? ['order' => null, 'ref' => $ref] : null;
    }

    public function confirm(array $input, array $headers, string $body, OrderInfo $order): Confirmation
    {
        $ref = (string) $order->gatewayRef;
        if ($ref === '' || ($input['payment_ref_id'] ?? null) !== $ref) {
            return Confirmation::invalid('payment_ref_id is not the order\'s');
        }
        $status = (string) ($input['status'] ?? '');
        if (in_array($status, ['Aborted', 'Cancelled'], true)) {
            return new Confirmation('cancelled', null, 'cancel:'.$ref, null, null, ['status' => $status]);
        }
        if (in_array($status, ['Failed', 'Fail', 'Invalid_Request'], true)) {
            return new Confirmation('failed', null, 'fail:'.$ref, null, null, ['status' => $status]);
        }
        if ($status !== 'Success') {
            return Confirmation::invalid('unexpected status', ['status' => $status]);
        }

        try {
            $res = $this->http->request('GET', rtrim($this->baseUrl, '/').'/verify/payment/'.rawurlencode($ref), $this->headers());
        } catch (\Throwable) {
            throw new GatewayFailure('nagad verify unreachable');
        }
        if ($res['status'] >= 500) {
            throw new GatewayFailure('nagad verify http '.$res['status']);
        }
        $j = $res['json'];
        $raw = ['paymentRefId' => $ref, 'status' => $j['status'] ?? null, 'statusCode' => $j['statusCode'] ?? null];
        if (($j['status'] ?? '') !== 'Success' || ($j['paymentRefId'] ?? $ref) !== $ref) {
            return Confirmation::invalid('verify says not successful', $raw);
        }
        if (($j['orderId'] ?? null) !== self::shortId($order->id)) {
            return Confirmation::invalid('orderId differs', $raw);
        }
        $minor = Amount::fromDecimal($j['amount'] ?? null);
        $txn = $j['issuerPaymentRefNo'] ?? null;
        if ($minor === null || ! is_string($txn) || $txn === '') {
            return Confirmation::invalid('no amount or issuer reference', $raw);
        }

        return new Confirmation('paid', $txn, $ref, $minor, 'BDT', $raw);
    }
}
