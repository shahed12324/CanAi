<?php

use App\Credits\Bars;
use App\Credits\CreditMath;

it('normalises credit amounts to 4 decimals', function (string|int $in, string $out) {
    expect(CreditMath::normalize($in))->toBe($out);
})->with([['12', '12.0000'], [7, '7.0000'], ['0.5', '0.5000'], [' 3.1234 ', '3.1234'], ['0', '0.0000']]);

it('rejects floats-as-text, negatives, junk and over-long numbers', function (string $bad) {
    expect(fn () => CreditMath::normalize($bad))->toThrow(InvalidArgumentException::class);
})->with(['1.23456', '-1', '1e3', 'abc', '', '1,000', '.5', '5.', '12345678901234567']);

it('knows what is positive', function () {
    expect(CreditMath::isPositive('0.0000'))->toBeFalse()
        ->and(CreditMath::isPositive('0.0001'))->toBeTrue()
        ->and(CreditMath::isPositive('10.0000'))->toBeTrue();
});

it('formats for people and for forms', function () {
    expect(CreditMath::format('1250.5000'))->toBe('1,250.5')
        ->and(CreditMath::format('0.0000'))->toBe('0')
        ->and(CreditMath::format(null))->toBe('0')
        ->and(CreditMath::format('-3.2000'))->toBe('-3.2')
        ->and(CreditMath::format('-0.0000'))->toBe('0')
        ->and(CreditMath::format('1000000.0000'))->toBe('1,000,000')
        ->and(CreditMath::plain('1250.5000'))->toBe('1250.5');
});

it('validates idempotency keys', function () {
    expect(CreditMath::validKey('signup:abc'))->toBeTrue()
        ->and(CreditMath::validKey('short'))->toBeFalse()
        ->and(CreditMath::validKey('has space here'))->toBeFalse()
        ->and(CreditMath::validKey(str_repeat('a', 129)))->toBeFalse();
});

it('scales chart bars, keeping tiny non-zero values visible', function () {
    $r = Bars::scale([['label' => 'a', 'total' => '10.0000'], ['label' => 'b', 'total' => '2.5000'], ['label' => 'c', 'total' => '0.0001'], ['label' => 'z', 'total' => '0']]);
    expect(array_column($r, 'pct'))->toBe([100, 25, 1, 0])
        ->and(Bars::scale([]))->toBe([])
        ->and(array_column(Bars::scale([['label' => 'a', 'total' => '0']]), 'pct'))->toBe([0]);
});
