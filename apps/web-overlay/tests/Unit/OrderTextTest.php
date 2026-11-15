<?php

use App\Orders\OrderText;

it('every database result code has a Bengali message; ok has none', function () {
    foreach (['emergency', 'bad_name', 'bad_phone', 'bad_address', 'bad_zone', 'no_items', 'bad_item', 'unavailable', 'insufficient_stock', 'mixed_currency', 'discount_too_high', 'too_many_open'] as $c) {
        expect(OrderText::createError($c) === null || OrderText::createError($c) === 'The order could not be created.')->toBeFalse();
    }
    expect(OrderText::createError('ok'))->toBeNull()->and(OrderText::transitionError('ok'))->toBeNull();
    expect(OrderText::createError('zzz'))->toBe('The order could not be created.')->and(OrderText::transitionError('bad_transition'))->toContain('বদলে');
});

it('the buttons follow the same path as the database; final states offer nothing', function () {
    expect(OrderText::actions('pending'))->toBe([['confirmed', 'Confirm'], ['cancelled', 'Cancel']]);
    expect(array_column(OrderText::actions('shipped'), 0))->toBe(['delivered', 'returned']);
    expect(array_column(OrderText::actions('delivered'), 0))->toBe(['returned']);
    expect(OrderText::actions('cancelled'))->toBe([])->and(OrderText::actions('returned'))->toBe([])->and(OrderText::actions('zzz'))->toBe([]);
});

it('money shows taka without trailing zeros and keeps paisa', function () {
    expect(OrderText::money(106000))->toBe('৳1,060')->and(OrderText::money(10050))->toBe('৳100.50')->and(OrderText::money(500, 'USD'))->toBe('USD 5');
});

it('form lines: bad ids, zero or huge quantities and junk are dropped', function () {
    $id = str_repeat('a', 26);
    expect(OrderText::items([['variant_id' => $id, 'qty' => '2'], ['variant_id' => 'short', 'qty' => 1], ['variant_id' => $id, 'qty' => 0], ['variant_id' => $id, 'qty' => 101], 'x', ['variant_id' => '', 'qty' => 1], ['qty' => 1]]))
        ->toBe([['variant_id' => $id, 'qty' => 2]]);
    expect(OrderText::items('nope'))->toBe([])->and(OrderText::items(null))->toBe([]);
    expect(count(OrderText::items(array_fill(0, 40, ['variant_id' => $id, 'qty' => 1]))))->toBe(20);
});

it('risk reasons and statuses are all translated', function () {
    foreach (['prev_bad', 'dup_open', 'fake_phone', 'short_address', 'big_qty', 'big_value', 'trusted'] as $c) {
        expect(OrderText::riskBn($c) === $c)->toBeFalse();
    }
    foreach (['pending', 'confirmed', 'packed', 'shipped', 'delivered', 'cancelled', 'returned'] as $s) {
        expect(OrderText::statusBn($s) === $s)->toBeFalse();
    }
});
