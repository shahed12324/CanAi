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
 * bKash Tokenized Checkout (grant token -> create -> customer pays -> callback -> execute). The result of execute (or, if that was
 * already done, of the payment query) is what we believe. Written from the public docs; not exercised against the live sandbox here.
 */
final class Bkash implements PaymentGateway
{
    public function __construct(
        private readonly HttpJson $http,
        private readonly string $appKey,
        private readonly string $appSecret,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $sandbox = true,
        private readonly ?string $baseUrl = null,
    ) {}

    public function code(): string
    {
        return 'bkash';
    }

    public function label(): string
    {
        return 'bKash';
    }

    public function currencies(): array
    {
        return ['BDT'];
    }

    private function base(): string
    {
        return rtrim($this->baseUrl ?? ($this->sandbox ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta' : 'https://tokenized.pay.bka.sh/v1.2.0-beta'), '/');
    }

    /** @return array<string, mixed> */
    private function call(string $path, array $body, ?string $token = null): array
    {
        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
        if ($token === null) {
            $headers += ['username' => $this->username, 'password' => $this->password];
        } else {
            $headers += ['Authorization' => $token, 'X-APP-Key' => $this->appKey];
        }
        try {
            $res = $this->http->request('POST', $this->base().$path, $headers, null, json_encode($body, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            throw new GatewayFailure('bkash unreachable');
        }
        if ($res['status'] >= 500) {
            throw new GatewayFailure('bkash http '.$res['status']);
        }

        return $res['json'];
    }

    private function token(): string
    {
        $j = $this->call('/tokenized/checkout/token/grant', ['app_key' => $this->appKey, 'app_secret' => $this->appSecret]);
        $t = $j['id_token'] ?? null;
        if (! is_string($t) || $t === '') {
            throw new GatewayFailure('bkash did not grant a token');
        }

        return $t;
    }

    public function initiate(OrderInfo $order, Urls $urls, Customer $customer): Initiation
    {
        $j = $this->call('/tokenized/checkout/create', [
            'mode' => '0011', 'payerReference' => substr($order->id, 0, 20), 'callbackURL' => $urls->return,
            'amount' => Amount::toDecimal($order->totalMinor), 'currency' => $order->currency, 'intent' => 'sale', 'merchantInvoiceNumber' => $order->id,
        ], $this->token());
        $id = $j['paymentID'] ?? null;
        $url = $j['bkashURL'] ?? null;
        if (($j['statusCode'] ?? '') !== '0000' || ! is_string($id) || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new GatewayFailure('bkash refused the payment');
        }

        return new Initiation($url, $id);
    }

    public function locate(array $input, array $headers, string $body): ?array
    {
        $id = $input['paymentID'] ?? null;

        return is_string($id) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $id) === 1 ? ['order' => null, 'ref' => $id] : null;
    }

    public function confirm(array $input, array $headers, string $body, OrderInfo $order): Confirmation
    {
        $status = strtolower((string) ($input['status'] ?? ''));
        $pid = (string) $order->gatewayRef;
        if (($input['paymentID'] ?? null) !== $pid || $pid === '') {
            return Confirmation::invalid('paymentID is not the order\'s');
        }
        if ($status === 'cancel') {
            return new Confirmation('cancelled', null, 'cancel:'.$pid, null, null, ['status' => $status]);
        }
        if ($status === 'failure') {
            return new Confirmation('failed', null, 'fail:'.$pid, null, null, ['status' => $status]);
        }
        if ($status !== 'success') {
            return Confirmation::invalid('unexpected status', ['status' => $status]);
        }

        $token = $this->token();
        $j = $this->call('/tokenized/checkout/execute', ['paymentID' => $pid], $token);
        if (($j['transactionStatus'] ?? '') !== 'Completed') {         // already executed (page refreshed) or lost reply: ask what happened
            $j = $this->call('/tokenized/checkout/payment/status', ['paymentID' => $pid], $token);
        }
        $raw = ['paymentID' => $pid, 'transactionStatus' => $j['transactionStatus'] ?? null, 'statusCode' => $j['statusCode'] ?? null];
        $ts = (string) ($j['transactionStatus'] ?? '');
        if ($ts !== 'Completed') {
            return in_array($ts, ['Initiated', 'Authorized'], true)
                ? new Confirmation('pending', null, null, null, null, $raw)
                : new Confirmation('failed', null, 'fail:'.$pid, null, null, $raw);
        }
        if (($j['merchantInvoiceNumber'] ?? null) !== $order->id) {
            return Confirmation::invalid('merchantInvoiceNumber differs', $raw);
        }
        $minor = Amount::fromDecimal($j['amount'] ?? null);
        $trx = $j['trxID'] ?? null;
        if ($minor === null || ! is_string($trx) || $trx === '') {
            return Confirmation::invalid('no amount or trxID', $raw);
        }

        return new Confirmation('paid', $trx, $pid, $minor, strtoupper((string) ($j['currency'] ?? 'BDT')), $raw + ['trxID' => $trx]);
    }
}
