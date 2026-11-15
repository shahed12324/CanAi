<?php

use App\Billing\Amount;
use App\Billing\BillingException;
use App\Billing\Customer;
use App\Billing\GatewayFailure;
use App\Billing\GatewayRegistry;
use App\Billing\Gateways\Demo;
use App\Billing\Gateways\SslCommerz;
use App\Billing\PaymentService;
use App\Billing\Urls;
use Tests\Support\FakeBillingRepo;
use Tests\Support\FakeHttpJson;

const TENANT = 'tenant00000000000000000001';

function svc(?FakeHttpJson $http = null, ?FakeBillingRepo $repo = null, array &$tenantsSeen = []): array
{
    $http ??= new FakeHttpJson;
    $repo ??= new FakeBillingRepo;
    $demo = new Demo('k', fn (string $id) => 'https://app.test/demo/'.$id);
    $reg = new GatewayRegistry([new SslCommerz($http, 'store1', 'secret1', true), $demo]);
    $seen = &$tenantsSeen;
    $service = new PaymentService($repo, $reg, function (string $tenant, Closure $cb) use (&$seen) {
        $seen[] = $tenant;

        return $cb();
    });

    return [$service, $repo, $http, $demo];
}

function urls(): Urls
{
    return new Urls('https://app.test/billing/return/sslcommerz', 'https://app.test/billing/ipn/sslcommerz', 'https://app.test/billing/cancel');
}

function sslSession(FakeHttpJson $http): void
{
    $http->queue[] = ['status' => 200, 'json' => ['status' => 'SUCCESS', 'GatewayPageURL' => 'https://sandbox.sslcommerz.com/gw/abc', 'sessionkey' => 'SESS1']];
}

function startOrder(PaymentService $s, string $gw = 'sslcommerz'): string
{
    return $s->checkout(TENANT, 'user', new Customer('Rahim', 'r@x.test'), 'subscription', 'planid', 'month', 'BDT', null, $gw, urls())->orderId;
}

function validation(array $o = []): array
{
    return ['status' => 200, 'json' => $o + ['status' => 'VALID', 'tran_id' => '', 'amount' => '1150.00', 'currency_type' => 'BDT', 'bank_tran_id' => 'BANK1', 'risk_level' => '0']];
}

it('formats amounts without floats', function () {
    expect(Amount::toDecimal(115000))->toBe('1150.00');
    expect(Amount::toDecimal(5))->toBe('0.05');
    expect(Amount::fromDecimal('1150.00'))->toBe(115000);
    expect(Amount::fromDecimal('1150.5'))->toBe(115050);
    expect(Amount::fromDecimal('1150'))->toBe(115000);
    expect(Amount::fromDecimal(1150))->toBe(115000);
    expect(Amount::fromDecimal('1150.001'))->toBeNull();
    expect(Amount::fromDecimal('-5'))->toBeNull();
    expect(Amount::fromDecimal('1e3'))->toBeNull();
    expect(Amount::fromDecimal(null))->toBeNull();
});

it('starts a checkout and remembers the gateway session', function () {
    [$s, $repo, $http] = svc();
    sslSession($http);
    $r = $s->checkout(TENANT, 'user', new Customer('Rahim', 'r@x.test'), 'subscription', 'planid', 'month', 'BDT', null, 'sslcommerz', urls());
    expect($r->redirectUrl)->toBe('https://sandbox.sslcommerz.com/gw/abc');
    expect($r->settled)->toBeFalse();
    expect($repo->orders[$r->orderId]['ref'])->toBe('SESS1');
    $form = $http->calls[0]['form'];
    expect($form['total_amount'])->toBe('1150.00');                          // the amount is the order's, not anything the browser sent
    expect($form['tran_id'])->toBe($r->orderId);
    expect($form['store_passwd'])->toBe('secret1');
});

it('closes the order when the gateway refuses the session', function () {
    [$s, $repo, $http] = svc();
    $http->queue[] = ['status' => 200, 'json' => ['status' => 'FAILED', 'failedreason' => 'x']];
    expect(fn () => startOrder($s))->toThrow(GatewayFailure::class);
    expect(array_values($repo->orders)[0]['status'])->toBe('failed');
});

it('refuses a gateway that does not take the currency or is not switched on', function () {
    [$s, $repo] = svc();
    expect(fn () => $s->checkout(TENANT, 'u', new Customer('a', 'a@x.test'), 'subscription', 'p', 'month', 'USD', null, 'sslcommerz', urls()))->toThrow(BillingException::class);
    expect(fn () => $s->checkout(TENANT, 'u', new Customer('a', 'a@x.test'), 'subscription', 'p', 'month', 'BDT', null, 'bkash', urls()))->toThrow(BillingException::class);
    expect($repo->orders)->toHaveCount(0);
});

it('settles a free order without calling any gateway', function () {
    [$s, $repo, $http] = svc();
    $r = $s->checkout(TENANT, 'u', new Customer('a', 'a@x.test'), 'subscription', 'p', 'month', 'BDT', 'FULL100', 'free', urls());
    expect($r->settled)->toBeTrue();
    expect($http->calls)->toHaveCount(0);
});

it('applies a verified IPN once and answers a repeat as a duplicate', function () {
    $seen = [];
    [$s, $repo, $http] = svc(null, null, $seen);
    sslSession($http);
    $id = startOrder($s);
    $ipn = ['status' => 'VALID', 'tran_id' => $id, 'val_id' => 'VAL1'];
    $http->queue[] = validation(['tran_id' => $id]);
    $first = $s->handleCallback('sslcommerz', $ipn, [], '');
    expect($first->outcome)->toBe('applied');
    expect($repo->orders[$id]['status'])->toBe('paid');
    expect($http->calls[1]['url'])->toStartWith('https://sandbox.sslcommerz.com/validator/api/validationserverAPI.php?val_id=VAL1');
    $http->queue[] = validation(['tran_id' => $id]);
    expect($s->handleCallback('sslcommerz', $ipn, [], '')->outcome)->toBe('duplicate');
    expect($repo->settleCalls)->toHaveCount(2);
    expect($seen[0])->toBe(TENANT);                                         // the work ran inside the order's workspace
});

it('does not believe a fake success redirect', function () {
    [$s, $repo, $http] = svc();
    sslSession($http);
    $id = startOrder($s);
    $http->queue[] = validation(['tran_id' => $id, 'status' => 'INVALID_TRANSACTION']);
    $r = $s->handleCallback('sslcommerz', ['status' => 'VALID', 'tran_id' => $id, 'val_id' => 'FORGED', 'amount' => '1150.00'], [], '');
    expect($r->outcome)->toBe('unverified');
    expect($repo->orders[$id]['status'])->toBe('pending');
    expect($repo->settleCalls)->toHaveCount(0);
    expect($repo->notes)->toHaveCount(1);
});

it('does not accept a real payment that belongs to another order', function () {
    [$s, $repo, $http] = svc();
    sslSession($http);
    $id = startOrder($s);
    $http->queue[] = validation(['tran_id' => 'someotherorder0000000000']);
    expect($s->handleCallback('sslcommerz', ['status' => 'VALID', 'tran_id' => $id, 'val_id' => 'V'], [], '')->outcome)->toBe('unverified');
    expect($repo->settleCalls)->toHaveCount(0);
});

it('passes the gateway\'s amount on, so a short payment is caught', function () {
    [$s, $repo, $http] = svc();
    sslSession($http);
    $id = startOrder($s);
    $http->queue[] = validation(['tran_id' => $id, 'amount' => '100.00']);
    $r = $s->handleCallback('sslcommerz', ['status' => 'VALID', 'tran_id' => $id, 'val_id' => 'V'], [], '');
    expect($r->outcome)->toBe('amount_mismatch');
    expect($repo->settleCalls[0]['amountMinor'])->toBe(10000);
    expect($repo->orders[$id]['status'])->toBe('review');
});

it('catches a wrong currency', function () {
    [$s, $repo, $http] = svc();
    sslSession($http);
    $id = startOrder($s);
    $http->queue[] = validation(['tran_id' => $id, 'currency_type' => 'USD']);
    expect($s->handleCallback('sslcommerz', ['status' => 'VALID', 'tran_id' => $id, 'val_id' => 'V'], [], '')->outcome)->toBe('currency_mismatch');
});

it('holds a risky payment for a human instead of applying it', function () {
    [$s, $repo, $http] = svc();
    sslSession($http);
    $id = startOrder($s);
    $http->queue[] = validation(['tran_id' => $id, 'risk_level' => '1']);
    expect($s->handleCallback('sslcommerz', ['status' => 'VALID', 'tran_id' => $id, 'val_id' => 'V'], [], '')->outcome)->toBe('unverified');
    expect($repo->settleCalls)->toHaveCount(0);
});

it('rejects a callback whose verify_sign is wrong without calling the gateway', function () {
    [$s, $repo, $http] = svc();
    sslSession($http);
    $id = startOrder($s);
    $before = count($http->calls);
    $in = ['status' => 'VALID', 'tran_id' => $id, 'val_id' => 'V', 'amount' => '1150.00', 'verify_key' => 'amount,tran_id', 'verify_sign' => str_repeat('0', 32)];
    expect($s->handleCallback('sslcommerz', $in, [], '')->outcome)->toBe('unverified');
    expect(count($http->calls))->toBe($before);
});

it('accepts a callback whose verify_sign is right', function () {
    [$s, $repo, $http] = svc();
    sslSession($http);
    $id = startOrder($s);
    $sign = md5('amount=1150.00&store_passwd='.md5('secret1').'&tran_id='.$id);
    $http->queue[] = validation(['tran_id' => $id]);
    $in = ['status' => 'VALID', 'tran_id' => $id, 'val_id' => 'V', 'amount' => '1150.00', 'verify_key' => 'amount,tran_id', 'verify_sign' => $sign];
    expect($s->handleCallback('sslcommerz', $in, [], '')->outcome)->toBe('applied');
});

it('marks failed and cancelled payments, but never touches a paid order', function () {
    [$s, $repo, $http] = svc();
    sslSession($http); $a = startOrder($s);
    sslSession($http); $b = startOrder($s);
    expect($s->handleCallback('sslcommerz', ['status' => 'FAILED', 'tran_id' => $a], [], '')->outcome)->toBe('failed');
    expect($s->handleCallback('sslcommerz', ['status' => 'CANCELLED', 'tran_id' => $b], [], '')->outcome)->toBe('cancelled');
    sslSession($http); $c = startOrder($s);
    $http->queue[] = validation(['tran_id' => $c]);
    $s->handleCallback('sslcommerz', ['status' => 'VALID', 'tran_id' => $c, 'val_id' => 'V'], [], '');
    expect($s->handleCallback('sslcommerz', ['status' => 'FAILED', 'tran_id' => $c], [], '')->outcome)->toBe('ignored');
    expect($repo->orders[$c]['status'])->toBe('paid');
});

it('lets the gateway retry when it could not be asked', function () {
    [$s, $repo, $http] = svc();
    sslSession($http);
    $id = startOrder($s);
    $http->queue[] = ['status' => 503, 'json' => []];
    expect(fn () => $s->handleCallback('sslcommerz', ['status' => 'VALID', 'tran_id' => $id, 'val_id' => 'V'], [], ''))->toThrow(GatewayFailure::class);
    expect($repo->orders[$id]['status'])->toBe('pending');
});

it('ignores callbacks for unknown orders, malformed ids and unknown gateways', function () {
    [$s, $repo, $http] = svc();
    expect($s->handleCallback('sslcommerz', ['status' => 'VALID', 'tran_id' => str_repeat('a', 26), 'val_id' => 'V'], [], '')->outcome)->toBe('unknown_order');
    expect($s->handleCallback('sslcommerz', ['status' => 'VALID', 'tran_id' => "x'; drop table orders;--", 'val_id' => 'V'], [], '')->outcome)->toBe('ignored');
    expect($s->handleCallback('paypal', [], [], '')->outcome)->toBe('unknown_order');
    expect($http->calls)->toHaveCount(0);
});

it('never lets one gateway\'s callback settle another gateway\'s order', function () {
    [$s, $repo, $http, $demo] = svc();
    $http->queue = [];
    $id = $repo->createOrder(TENANT, 'u', 'subscription', 'p', 'month', 'BDT', null, 'demo');   // a demo order...
    $repo->setRef($id, 'demo_'.$id);
    $r = $s->handleCallback('sslcommerz', ['status' => 'VALID', 'tran_id' => $id, 'val_id' => 'V'], [], '');   // ...hit through the SSLCommerz endpoint
    expect($r->outcome)->toBe('gateway_mismatch');
    expect($repo->settleCalls)->toHaveCount(0);
});

it('demo gateway pays only with a signature made for this order, amount and result', function () {
    [$s, $repo, $http, $demo] = svc();
    $id = startOrder($s, 'demo');
    expect($repo->orders[$id]['ref'])->toBe('demo_'.$id);
    $other = str_pad('f', 26, '0', STR_PAD_LEFT);
    $in = fn (string $order, string $result, ?string $sig = null) => ['ref' => 'demo_'.$id, 'result' => $result, 'sig' => $sig ?? $demo->sign($order, 115000, $result)];
    expect($s->handleCallback('demo', $in($other, 'paid'), [], '')->outcome)->toBe('unverified');          // signature of another order
    expect($s->handleCallback('demo', $in($id, 'paid', 'deadbeef'), [], '')->outcome)->toBe('unverified');
    expect($s->handleCallback('demo', ['ref' => 'demo_'.$id, 'result' => 'paid', 'sig' => $demo->sign($id, 100, 'paid')], [], '')->outcome)->toBe('unverified');   // signed for a smaller amount
    expect($repo->orders[$id]['status'])->toBe('pending');
    expect($s->handleCallback('demo', $in($id, 'paid'), [], '')->outcome)->toBe('applied');
    expect($s->handleCallback('demo', $in($id, 'paid'), [], '')->outcome)->toBe('duplicate');
});

it('knows its gateways by currency', function () {
    [$s] = svc();
    $reg = new GatewayRegistry([new Demo('k', fn ($i) => 'x'), new SslCommerz(new FakeHttpJson, 'a', 'b')]);
    expect(array_map(fn ($g) => $g->code(), $reg->forCurrency('BDT')))->toBe(['demo', 'sslcommerz']);
    expect(array_map(fn ($g) => $g->code(), $reg->forCurrency('USD')))->toBe(['demo']);
});
