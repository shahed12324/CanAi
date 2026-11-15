<?php

use App\Reseller\ResellerService;
use Tests\Support\FakeResellerRepo;

function rsvc(): array
{
    $r = new FakeResellerRepo;

    return [new ResellerService($r), $r];
}

it('cleans a typed code: trims, drops spaces and dashes, upper-cases', function () {
    expect(ResellerService::cleanCode('  ab-cd 12 '))->toBe('ABCD12')->and(ResellerService::cleanCode("pa\trt-ner1"))->toBe('PARTNER1');
});

it('sends a well-formed code to the repo in clean form', function () {
    [$s, $r] = rsvc();
    expect($s->join('t', 'u', ' partner-1 '))->toBe('ok')->and($r->joins)->toBe([['t', 'u', 'PARTNER1']]);
});

it('never asks the database about a malformed code', function () {
    [$s, $r] = rsvc();
    foreach (['', 'abc', "' OR 1=1 --", str_repeat('a', 13), 'ab cd!ef', 'বাংলা১২৩৪৫৬'] as $bad) {
        expect($s->join('t', 'u', $bad))->toBe('not_found');
    }
    expect($r->joins)->toBe([]);
});

it('passes the repo answer through and has a plain message for every answer', function () {
    [$s, $r] = rsvc();
    foreach (['already', 'not_found', 'not_owner', 'self', 'ok'] as $a) {
        $r->joinResult = $a;
        expect($s->join('t', 'u', 'PARTNER1'))->toBe($a)->and(ResellerService::message($a))->not->toBe('The action could not be done.');
    }
    expect(ResellerService::message('weird'))->toBe('The action could not be done.');
});

it('formats minor units, including zero, small and negative amounts', function () {
    expect(ResellerService::fmt(0))->toBe('0.00')->and(ResellerService::fmt(5))->toBe('0.05')->and(ResellerService::fmt(1299))->toBe('12.99')->and(ResellerService::fmt(-1000))->toBe('-10.00')->and(ResellerService::fmt(-5))->toBe('-0.05')->and(ResellerService::fmt(100000))->toBe('1000.00');
});

it('turns the report into sorted money lines per currency', function () {
    [$s, $r] = rsvc();
    expect($s->report('t', 'u'))->toBeNull();
    $r->report = ['customers' => [['name' => 'X', 'joined_at' => 'd', 'paid_orders' => 2]], 'money' => ['USD' => ['revenue' => 2000, 'commission' => 200, 'paid_out' => 0, 'due' => 200], 'BDT' => ['revenue' => 12999, 'commission' => 1299, 'paid_out' => 1000, 'due' => 299]]];
    $o = $s->report('t', 'u');
    expect(array_column($o['money'], 'currency'))->toBe(['BDT', 'USD'])->and($o['money'][0])->toBe(['currency' => 'BDT', 'revenue' => '129.99', 'commission' => '12.99', 'paid_out' => '10.00', 'due' => '2.99'])->and($o['customers'][0]['name'])->toBe('X');
});

it('joinedTo and me are plain pass-throughs', function () {
    [$s, $r] = rsvc();
    $r->of = 'Acme';
    $r->me = ['id' => 'x', 'name' => 'n', 'code' => 'C', 'commission_bp' => 10, 'status' => 'active'];
    expect($s->joinedTo('t', 'u'))->toBe('Acme')->and($s->me('t', 'u')['code'])->toBe('C');
});
