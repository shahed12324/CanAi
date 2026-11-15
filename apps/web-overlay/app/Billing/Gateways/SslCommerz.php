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
 * SSLCommerz hosted checkout. The success/fail/IPN callbacks are only a hint; the Order Validation API decides.
 * Written from the public integration guide; not exercised against the live sandbox in this repository's tests.
 */
final class SslCommerz implements PaymentGateway
{
    public function __construct(private readonly HttpJson $http, private readonly string $storeId, private readonly string $storePassword, private readonly bool $sandbox = true) {}

    public function code(): string
    {
        return 'sslcommerz';
    }

    public function label(): string
    {
        return 'SSLCommerz (Card / mobile banking / net banking)';
    }

    public function currencies(): array
    {
        return ['BDT'];
    }

    private function base(): string
    {
        return $this->sandbox ? 'https://sandbox.sslcommerz.com' : 'https://securepay.sslcommerz.com';
    }

    public function initiate(OrderInfo $order, Urls $urls, Customer $customer): Initiation
    {
        try {
            $res = $this->http->request('POST', $this->base().'/gwprocess/v4/api.php', [], [
                'store_id' => $this->storeId, 'store_passwd' => $this->storePassword,
                'total_amount' => Amount::toDecimal($order->totalMinor), 'currency' => $order->currency,
                'tran_id' => $order->id, 'success_url' => $urls->return, 'fail_url' => $urls->return, 'cancel_url' => $urls->cancel, 'ipn_url' => $urls->ipn,
                'cus_name' => mb_substr($customer->name, 0, 50), 'cus_email' => $customer->email, 'cus_add1' => 'Dhaka', 'cus_city' => 'Dhaka',
                'cus_country' => 'Bangladesh', 'cus_phone' => '01700000000', 'shipping_method' => 'NO', 'num_of_item' => '1',
                'product_name' => mb_substr($order->itemName, 0, 100), 'product_category' => 'Software', 'product_profile' => 'non-physical-goods',
            ]);
        } catch (\Throwable) {
            throw new GatewayFailure('sslcommerz unreachable');
        }
        $url = $res['json']['GatewayPageURL'] ?? null;
        if ($res['status'] !== 200 || ($res['json']['status'] ?? '') !== 'SUCCESS' || ! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new GatewayFailure('sslcommerz refused the session');
        }

        return new Initiation($url, (string) ($res['json']['sessionkey'] ?? $order->id));
    }

    public function locate(array $input, array $headers, string $body): ?array
    {
        $tran = $input['tran_id'] ?? null;

        return is_string($tran) && preg_match('/\A[0-9a-z]{26}\z/', $tran) === 1 ? ['order' => $tran, 'ref' => null] : null;
    }

    public function confirm(array $input, array $headers, string $body, OrderInfo $order): Confirmation
    {
        $status = strtoupper((string) ($input['status'] ?? ''));
        $raw = ['status' => $status, 'tran_id' => $input['tran_id'] ?? null];
        if ($status === 'FAILED') {
            return new Confirmation('failed', null, 'fail:'.($input['val_id'] ?? $order->id), null, null, $raw);
        }
        if ($status === 'CANCELLED') {
            return new Confirmation('cancelled', null, 'cancel:'.$order->id, null, null, $raw);
        }
        if (! in_array($status, ['VALID', 'VALIDATED'], true)) {
            return Confirmation::invalid('unexpected status', $raw);
        }
        $valId = $input['val_id'] ?? null;
        if (! is_string($valId) || $valId === '' || strlen($valId) > 100) {
            return Confirmation::invalid('no val_id', $raw);
        }
        if (! $this->signatureOk($input)) {
            return Confirmation::invalid('bad verify_sign', $raw);
        }

        try {
            $res = $this->http->request('GET', $this->base().'/validator/api/validationserverAPI.php?'.http_build_query([
                'val_id' => $valId, 'store_id' => $this->storeId, 'store_passwd' => $this->storePassword, 'format' => 'json',
            ]));
        } catch (\Throwable) {
            throw new GatewayFailure('sslcommerz validation unreachable');
        }
        if ($res['status'] !== 200) {
            throw new GatewayFailure('sslcommerz validation http '.$res['status']);
        }
        $j = $res['json'];
        $raw += ['val_id' => $valId, 'validated' => $j['status'] ?? null, 'amount' => $j['amount'] ?? null, 'risk' => $j['risk_level'] ?? null];
        if (! in_array(strtoupper((string) ($j['status'] ?? '')), ['VALID', 'VALIDATED'], true)) {
            return Confirmation::invalid('validation says not valid', $raw);
        }
        if (($j['tran_id'] ?? null) !== $order->id) {                  // a real payment, but for another order: never ours to apply
            return Confirmation::invalid('tran_id differs', $raw);
        }
        if ((string) ($j['risk_level'] ?? '0') === '1') {              // the gateway flags it as risky: hold for a human
            return Confirmation::invalid('high risk', $raw);
        }
        $minor = Amount::fromDecimal($j['amount'] ?? null);
        $currency = strtoupper((string) ($j['currency_type'] ?? $j['currency'] ?? ''));
        if ($minor === null || $currency === '') {
            return Confirmation::invalid('no amount', $raw);
        }
        $txn = (string) (($j['bank_tran_id'] ?? '') !== '' ? $j['bank_tran_id'] : $valId);

        return new Confirmation('paid', $txn, $valId, $minor, $currency, $raw);
    }

    /** verify_sign = md5 over the fields named in verify_key (sorted) plus md5(store password). Checked only when the callback carries one. */
    private function signatureOk(array $input): bool
    {
        $sign = $input['verify_sign'] ?? null;
        $keys = $input['verify_key'] ?? null;
        if (! is_string($sign) || ! is_string($keys)) {
            return true;                                                // the validation API below is the authority
        }
        $data = [];
        foreach (explode(',', $keys) as $k) {
            $data[$k] = (string) ($input[$k] ?? '');
        }
        $data['store_passwd'] = md5($this->storePassword);
        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = $k.'='.$v;
        }

        return hash_equals(md5(implode('&', $pairs)), strtolower($sign));
    }
}
