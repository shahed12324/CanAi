<?php

use App\Billing\CallbackResult;
use App\Billing\GatewayFactory;
use App\Billing\Gateways\Rsa;
use App\Billing\Present;
use Tests\Support\FakeHttpJson;

function reg(array $c)
{
    return GatewayFactory::make($c, new FakeHttpJson, 'base64:appkey', fn (string $id) => 'https://app.test/demo/'.$id);
}

it('switches a gateway on only when all its keys are present', function () {
    expect(reg([])->all())->toHaveCount(0);
    expect(reg(['sslcommerz' => ['store_id' => 'a', 'store_password' => null]])->all())->toHaveCount(0);
    $all = reg([
        'demo' => true,
        'sslcommerz' => ['store_id' => 'a', 'store_password' => 'b'],
        'bkash' => ['app_key' => '1', 'app_secret' => '2', 'username' => '3', 'password' => '4'],
        'stripe' => ['secret_key' => 'sk', 'webhook_secret' => 'wh', 'currencies' => ['usd', 'eur']],
    ]);
    expect(array_map(fn ($g) => $g->code(), $all->all()))->toBe(['sslcommerz', 'bkash', 'stripe', 'demo']);
    expect($all->get('stripe')->currencies())->toBe(['USD', 'EUR']);
});

it('refuses a Nagad base URL that is not https', function () {
    $nagad = fn (string $base) => ['nagad' => ['merchant_id' => 'm', 'merchant_private_key' => 'k', 'pg_public_key' => 'p', 'base' => $base]];
    expect(reg($nagad('http://sandbox.mynagad.com:10080/x'))->all())->toHaveCount(0);
    expect(reg($nagad('https://api.mynagad.com/api/dfs'))->all())->toHaveCount(1);
});

it('wraps a bare base64 key as PEM and leaves PEM alone', function () {
    expect(Rsa::pem("AAAA\nBBBB", 'PUBLIC KEY'))->toBe("-----BEGIN PUBLIC KEY-----\nAAAABBBB\n-----END PUBLIC KEY-----");
    expect(Rsa::pem("-----BEGIN PUBLIC KEY-----\nAA\n-----END PUBLIC KEY-----", 'PUBLIC KEY'))->toStartWith('-----BEGIN PUBLIC KEY-----');
});

it('tells the gateway to retry only when we did not handle the callback', function () {
    expect((new CallbackResult('applied'))->acknowledge())->toBeTrue();
    expect((new CallbackResult('unverified'))->acknowledge())->toBeTrue();
    expect((new CallbackResult('unknown_order'))->acknowledge())->toBeFalse();
    expect((new CallbackResult('duplicate'))->isPaid())->toBeTrue();
    expect((new CallbackResult('failed'))->isPaid())->toBeFalse();
});

it('prints money in taka and other currencies', function () {
    expect(Present::money(115000, 'BDT'))->toBe('৳ ১,১৫০');
    expect(Present::money(1050, 'USD'))->toBe('USD 10.50');
    expect(Present::bp(1500))->toBe('15%');
    expect(Present::bp(750))->toBe('7.5%');
});
