<?php

use App\Billing\Confirmation;
use App\Billing\Customer;
use App\Billing\GatewayFailure;
use App\Billing\Gateways\Bkash;
use App\Billing\Gateways\Nagad;
use App\Billing\Gateways\Rsa;
use App\Billing\Gateways\Stripe;
use App\Billing\OrderInfo;
use App\Billing\Urls;
use App\Outreach\HttpJson;
use Tests\Support\FakeHttpJson;

function ord1(string $gw, string $currency = 'BDT', ?string $ref = null, int $total = 115000): OrderInfo
{
    return new OrderInfo('o00000000000000000000000a1', 'tenant00000000000000000001', $gw, $currency, $total, 'Pro - monthly', $ref, 'pending');
}

function urls2(): Urls
{
    return new Urls('https://app.test/billing/return/x', 'https://app.test/billing/ipn/x', 'https://app.test/billing/cancel');
}

const WHO = [];

// ---------------------------------------------------------------- bKash
function bk(FakeHttpJson $h): Bkash
{
    return new Bkash($h, 'appkey', 'appsecret', 'user', 'pass', true);
}

function grant(FakeHttpJson $h): void
{
    $h->queue[] = ['status' => 200, 'json' => ['statusCode' => '0000', 'id_token' => 'TOKEN']];
}

it('bkash: grants a token, creates the payment and returns the pay page', function () {
    $h = new FakeHttpJson;
    grant($h);
    $h->queue[] = ['status' => 200, 'json' => ['statusCode' => '0000', 'paymentID' => 'PID1', 'bkashURL' => 'https://sandbox.pay.bka.sh/x']];
    $i = bk($h)->initiate(ord1('bkash'), urls2(), new Customer('R', 'r@x.test'));
    expect($i->redirectUrl)->toBe('https://sandbox.pay.bka.sh/x');
    expect($i->ref)->toBe('PID1');
    expect($h->calls[0]['headers']['username'])->toBe('user');
    expect($h->calls[1]['headers']['Authorization'])->toBe('TOKEN');
    $b = json_decode($h->calls[1]['body'], true);
    expect($b['amount'])->toBe('1150.00');
    expect($b['merchantInvoiceNumber'])->toBe('o00000000000000000000000a1');
    expect($b['callbackURL'])->toBe('https://app.test/billing/return/x');
});

it('bkash: refuses when no token or an error code comes back', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ['status' => 200, 'json' => ['statusCode' => '2001', 'statusMessage' => 'Invalid App Key']];
    expect(fn () => bk($h)->initiate(ord1('bkash'), urls2(), new Customer('R', 'r@x.test')))->toThrow(GatewayFailure::class);
    $h2 = new FakeHttpJson;
    grant($h2);
    $h2->queue[] = ['status' => 200, 'json' => ['statusCode' => '2062']];
    expect(fn () => bk($h2)->initiate(ord1('bkash'), urls2(), new Customer('R', 'r@x.test')))->toThrow(GatewayFailure::class);
});

it('bkash: executes after the customer returns and trusts only the execute answer', function () {
    $h = new FakeHttpJson;
    grant($h);
    $h->queue[] = ['status' => 200, 'json' => ['statusCode' => '0000', 'transactionStatus' => 'Completed', 'trxID' => 'TRX9', 'amount' => '1150', 'currency' => 'BDT', 'merchantInvoiceNumber' => 'o00000000000000000000000a1']];
    $c = bk($h)->confirm(['paymentID' => 'PID1', 'status' => 'success'], [], '', ord1('bkash', 'BDT', 'PID1'));
    expect($c->status)->toBe('paid');
    expect([$c->txnId, $c->amountMinor, $c->currency])->toBe(['TRX9', 115000, 'BDT']);
    expect($h->calls[1]['url'])->toEndWith('/tokenized/checkout/execute');
});

it('bkash: a refreshed return page asks the payment status instead of executing twice', function () {
    $h = new FakeHttpJson;
    grant($h);
    $h->queue[] = ['status' => 200, 'json' => ['statusCode' => '2062', 'statusMessage' => 'already executed']];
    $h->queue[] = ['status' => 200, 'json' => ['statusCode' => '0000', 'transactionStatus' => 'Completed', 'trxID' => 'TRX9', 'amount' => '1150.00', 'currency' => 'BDT', 'merchantInvoiceNumber' => 'o00000000000000000000000a1']];
    $c = bk($h)->confirm(['paymentID' => 'PID1', 'status' => 'success'], [], '', ord1('bkash', 'BDT', 'PID1'));
    expect($c->status)->toBe('paid');
    expect($h->calls[2]['url'])->toEndWith('/tokenized/checkout/payment/status');
});

it('bkash: a payment for another invoice, another paymentID, or a bad status is never paid', function () {
    $mk = function (array $exec) {
        $h = new FakeHttpJson;
        grant($h);
        $h->queue[] = ['status' => 200, 'json' => $exec];

        return $h;
    };
    $good = ['statusCode' => '0000', 'transactionStatus' => 'Completed', 'trxID' => 'T', 'amount' => '1150.00', 'currency' => 'BDT', 'merchantInvoiceNumber' => 'someoneelse'];
    expect(bk($mk($good))->confirm(['paymentID' => 'PID1', 'status' => 'success'], [], '', ord1('bkash', 'BDT', 'PID1'))->status)->toBe('invalid');
    $h = new FakeHttpJson;
    expect(bk($h)->confirm(['paymentID' => 'OTHER', 'status' => 'success'], [], '', ord1('bkash', 'BDT', 'PID1'))->status)->toBe('invalid');
    expect($h->calls)->toHaveCount(0);
    expect(bk($h)->confirm(['paymentID' => 'PID1', 'status' => 'cancel'], [], '', ord1('bkash', 'BDT', 'PID1'))->status)->toBe('cancelled');
    expect(bk($h)->confirm(['paymentID' => 'PID1', 'status' => 'failure'], [], '', ord1('bkash', 'BDT', 'PID1'))->status)->toBe('failed');
    expect(bk($h)->confirm(['paymentID' => 'PID1', 'status' => 'hacked'], [], '', ord1('bkash', 'BDT', 'PID1'))->status)->toBe('invalid');
    $initiated = ['statusCode' => '0000', 'transactionStatus' => 'Initiated'];
    $h2 = new FakeHttpJson;
    grant($h2);
    $h2->queue[] = ['status' => 200, 'json' => ['statusCode' => '2023']];
    $h2->queue[] = ['status' => 200, 'json' => $initiated];
    expect(bk($h2)->confirm(['paymentID' => 'PID1', 'status' => 'success'], [], '', ord1('bkash', 'BDT', 'PID1'))->status)->toBe('pending');
});

it('bkash: finds the order by paymentID', function () {
    expect(bk(new FakeHttpJson)->locate(['paymentID' => 'PID1'], [], ''))->toBe(['order' => null, 'ref' => 'PID1']);
    expect(bk(new FakeHttpJson)->locate(['paymentID' => "a b'"], [], ''))->toBeNull();
    expect(bk(new FakeHttpJson)->locate([], [], ''))->toBeNull();
});

// ---------------------------------------------------------------- Stripe
const WHSEC = 'whsec_test';

function st(FakeHttpJson $h): Stripe
{
    return new Stripe($h, 'sk_test_x', WHSEC, ['USD'], fn () => 1_800_000_000);
}

function stripeSig(string $body, int $t = 1_800_000_000, string $secret = WHSEC): string
{
    return 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $secret);
}

function evt(string $type = 'checkout.session.completed', string $id = 'cs_test_1'): string
{
    return json_encode(['id' => 'evt_1', 'type' => $type, 'data' => ['object' => ['id' => $id]]]);
}

it('stripe: creates a checkout session with the order\'s exact amount', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ['status' => 200, 'json' => ['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1']];
    $i = st($h)->initiate(ord1('stripe', 'USD', null, 1000), urls2(), new Customer('R', 'r@x.test'));
    expect($i->ref)->toBe('cs_test_1');
    $f = $h->calls[0]['form'];
    expect($f['line_items[0][price_data][unit_amount]'])->toBe('1000');
    expect($f['line_items[0][price_data][currency]'])->toBe('usd');
    expect($f['client_reference_id'])->toBe('o00000000000000000000000a1');
    expect($f['success_url'])->toContain('session_id={CHECKOUT_SESSION_ID}');
    expect($h->calls[0]['headers']['Authorization'])->toBe('Bearer sk_test_x');
});

it('stripe: accepts a webhook only with a valid, fresh signature', function () {
    $body = evt();
    $h = new FakeHttpJson;
    expect(st($h)->locate([], ['stripe-signature' => stripeSig($body)], $body))->toBe(['order' => null, 'ref' => 'cs_test_1']);
    expect(fn () => st($h)->locate([], ['stripe-signature' => stripeSig($body, 1_800_000_000, 'other')], $body))->toThrow(GatewayFailure::class);
    expect(fn () => st($h)->locate([], ['stripe-signature' => stripeSig($body, 1_800_000_000 - 301)], $body))->toThrow(GatewayFailure::class);      // replay of an old one
    expect(fn () => st($h)->locate([], ['stripe-signature' => stripeSig($body)], $body.' '))->toThrow(GatewayFailure::class);                           // body tampered with
    expect(st($h)->locate([], ['stripe-signature' => stripeSig(evt('charge.refunded'))], evt('charge.refunded')))->toBeNull();
});

it('stripe: the customer returning is located by session id', function () {
    expect(st(new FakeHttpJson)->locate(['session_id' => 'cs_test_1'], [], ''))->toBe(['order' => null, 'ref' => 'cs_test_1']);
    expect(st(new FakeHttpJson)->locate(['session_id' => 'evil'], [], ''))->toBeNull();
});

it('stripe: confirms by fetching the session, not by trusting the webhook', function () {
    $h = new FakeHttpJson;
    $h->queue[] = ['status' => 200, 'json' => ['id' => 'cs_test_1', 'payment_status' => 'paid', 'status' => 'complete', 'client_reference_id' => 'o00000000000000000000000a1', 'amount_total' => 1000, 'currency' => 'usd', 'payment_intent' => 'pi_1']];
    $c = st($h)->confirm([], [], '', ord1('stripe', 'USD', 'cs_test_1', 1000));
    expect([$c->status, $c->txnId, $c->amountMinor, $c->currency, $c->eventId])->toBe(['paid', 'pi_1', 1000, 'USD', 'cs_test_1']);
    expect($h->calls[0]['method'])->toBe('GET');
    expect($h->calls[0]['url'])->toBe('https://api.stripe.com/v1/checkout/sessions/cs_test_1');
});

it('stripe: unpaid, expired, foreign or unreachable sessions are not paid', function () {
    $session = fn (array $o) => ['status' => 200, 'json' => $o + ['id' => 'cs_test_1', 'payment_status' => 'unpaid', 'status' => 'open', 'client_reference_id' => 'o00000000000000000000000a1']];
    $run = function (array $q) {
        $h = new FakeHttpJson;
        $h->queue[] = $q;

        return st($h)->confirm([], [], '', ord1('stripe', 'USD', 'cs_test_1', 1000));
    };
    expect($run($session([]))->status)->toBe('pending');
    expect($run($session(['status' => 'expired']))->status)->toBe('cancelled');
    expect($run($session(['client_reference_id' => 'other', 'payment_status' => 'paid', 'payment_intent' => 'pi', 'amount_total' => 1000]))->status)->toBe('invalid');
    expect($run($session(['id' => 'cs_other', 'payment_status' => 'paid', 'payment_intent' => 'pi', 'amount_total' => 1000]))->status)->toBe('invalid');
    expect($run(['status' => 404, 'json' => []])->status)->toBe('invalid');
    expect(fn () => $run(['status' => 502, 'json' => []]))->toThrow(GatewayFailure::class);
});

// ---------------------------------------------------------------- Nagad (talks to a small fake Nagad that really decrypts, verifies and encrypts)
function keypair(): array
{
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($k, $priv);

    return [$priv, openssl_pkey_get_details($k)['key']];
}

final class FakeNagadServer implements HttpJson
{
    public array $log = [];

    public string $verifyStatus = 'Success';

    public string $verifyAmount = '1150.00';

    public function __construct(private string $merchantPub, private string $nagadPriv, private string $merchantPubForVerify) {}

    public function request(string $method, string $url, array $headers = [], ?array $form = null, ?string $body = null): array
    {
        $this->log[] = [$method, $url, $headers, $body];
        $b = $body === null ? [] : json_decode($body, true);
        if (str_contains($url, '/check-out/initialize/')) {
            openssl_private_decrypt(base64_decode($b['sensitiveData']), $plain, $this->nagadPriv, OPENSSL_PKCS1_PADDING);
            $ok = openssl_verify($plain, base64_decode($b['signature']), $this->merchantPubForVerify, OPENSSL_ALGO_SHA256) === 1;
            $d = json_decode($plain, true);
            if (! $ok || strlen($d['orderId']) > 20) {
                return ['status' => 200, 'json' => ['reason' => 'bad signature']];
            }
            openssl_public_encrypt(json_encode(['paymentReferenceId' => 'REF1', 'challenge' => $d['challenge']]), $enc, $this->merchantPub, OPENSSL_PKCS1_PADDING);

            return ['status' => 200, 'json' => ['sensitiveData' => base64_encode($enc), 'signature' => 'x']];
        }
        if (str_contains($url, '/check-out/complete/REF1')) {
            openssl_private_decrypt(base64_decode($b['sensitiveData']), $plain, $this->nagadPriv, OPENSSL_PKCS1_PADDING);
            $d = json_decode($plain, true);
            $this->log[count($this->log) - 1][] = $d;

            return ['status' => 200, 'json' => ['status' => 'Success', 'callBackUrl' => 'https://pay.nagad.test/redirect/REF1']];
        }
        if (str_contains($url, '/verify/payment/REF1')) {
            return ['status' => 200, 'json' => ['status' => $this->verifyStatus, 'paymentRefId' => 'REF1', 'orderId' => 'o0000000000000000000', 'amount' => $this->verifyAmount, 'issuerPaymentRefNo' => 'ISS1']];
        }

        return ['status' => 404, 'json' => []];
    }
}

function nagadSetup(): array
{
    [$mp, $mpub] = keypair();
    [$np, $npub] = keypair();
    $server = new FakeNagadServer($mpub, $np, $mpub);
    $nagad = new Nagad($server, 'MERCH1', new Rsa($mp, $npub), 'https://api.nagad.test/api/dfs', fn () => '20261103101500', fn () => 'chal-1234');

    return [$nagad, $server];
}

it('nagad: initializes and completes with encrypted, signed requests and returns the redirect', function () {
    [$nagad, $server] = nagadSetup();
    $i = $nagad->initiate(ord1('nagad'), urls2(), new Customer('R', 'r@x.test'));
    expect($i->redirectUrl)->toBe('https://pay.nagad.test/redirect/REF1');
    expect($i->ref)->toBe('REF1');
    $complete = $server->log[1];
    expect($complete[4]['amount'])->toBe('1150.00');
    expect($complete[4]['challenge'])->toBe('chal-1234');
    expect($complete[4]['orderId'])->toBe('o0000000000000000000');          // 20-character merchant order id
    expect($complete[2]['X-KM-Api-Version'])->toBe('v-0.2.0');
    expect(str_contains($server->log[0][3], 'MERCH1'))->toBeFalse();       // nothing sensitive travels in the clear
});

it('nagad: refuses when Nagad does not accept the signature', function () {
    [$mp, $mpub] = keypair();
    [$np, $npub] = keypair();
    [, $otherPub] = keypair();
    $server = new FakeNagadServer($mpub, $np, $otherPub);                    // the server checks against a different merchant key
    $nagad = new Nagad($server, 'MERCH1', new Rsa($mp, $npub), 'https://api.nagad.test/api/dfs');
    expect(fn () => $nagad->initiate(ord1('nagad'), urls2(), new Customer('R', 'r@x.test')))->toThrow(GatewayFailure::class);
});

it('nagad: confirms by calling verify and checks reference, order id and amount', function () {
    [$nagad, $server] = nagadSetup();
    $c = $nagad->confirm(['payment_ref_id' => 'REF1', 'status' => 'Success'], [], '', ord1('nagad', 'BDT', 'REF1'));
    expect([$c->status, $c->txnId, $c->amountMinor, $c->currency])->toBe(['paid', 'ISS1', 115000, 'BDT']);
    $server->verifyAmount = '100.00';
    expect($nagad->confirm(['payment_ref_id' => 'REF1', 'status' => 'Success'], [], '', ord1('nagad', 'BDT', 'REF1'))->amountMinor)->toBe(10000);   // the DB then rejects it
    $server->verifyStatus = 'Failed';
    expect($nagad->confirm(['payment_ref_id' => 'REF1', 'status' => 'Success'], [], '', ord1('nagad', 'BDT', 'REF1'))->status)->toBe('invalid');
});

it('nagad: callback claims alone never pay, and foreign references are refused', function () {
    [$nagad, $server] = nagadSetup();
    $n = count($server->log);
    expect($nagad->confirm(['payment_ref_id' => 'OTHER', 'status' => 'Success'], [], '', ord1('nagad', 'BDT', 'REF1'))->status)->toBe('invalid');
    expect($nagad->confirm(['payment_ref_id' => 'REF1', 'status' => 'Aborted'], [], '', ord1('nagad', 'BDT', 'REF1'))->status)->toBe('cancelled');
    expect($nagad->confirm(['payment_ref_id' => 'REF1', 'status' => 'Failed'], [], '', ord1('nagad', 'BDT', 'REF1'))->status)->toBe('failed');
    expect($nagad->confirm(['payment_ref_id' => 'REF1', 'status' => 'Whatever'], [], '', ord1('nagad', 'BDT', 'REF1'))->status)->toBe('invalid');
    expect(count($server->log))->toBe($n);
    $other = new OrderInfo('z00000000000000000000000z9', 'tenant00000000000000000001', 'nagad', 'BDT', 115000, 'x', 'REF1', 'pending');
    expect($nagad->confirm(['payment_ref_id' => 'REF1', 'status' => 'Success'], [], '', $other)->status)->toBe('invalid');   // REF1 verified, but it is for a different order
});
