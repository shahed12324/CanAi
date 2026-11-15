<?php

use App\Vat\VatBooks;
use App\Vat\VatCalc;

it('computes VAT in whole poisha, half-up', function (int $sub, int $bp, int $want) {
    expect(VatCalc::vat($sub, $bp))->toBe($want);
})->with([[100000, 1500, 15000], [1, 1500, 0], [4, 1500, 1], [3, 1500, 0], [33, 750, 2], [0, 1500, 0], [10000000000, 1500, 1500000000], [999, 0, 0], [100, 10000, 100]]);

it('refuses impossible VAT input', function () {
    expect(fn () => VatCalc::vat(-1, 1500))->toThrow(InvalidArgumentException::class);
    expect(fn () => VatCalc::vat(1, 10001))->toThrow(InvalidArgumentException::class);
});

const RATES = [
    ['code' => 'STD', 'rate_bp' => 1500, 'effective_from' => '2026-01-01', 'effective_to' => '2026-11-30'],
    ['code' => 'STD', 'rate_bp' => 1000, 'effective_from' => '2026-12-01', 'effective_to' => null],
    ['code' => 'ZERO', 'rate_bp' => 0, 'effective_from' => '2012-07-01', 'effective_to' => null],
];

it('finds the rate in force on a date', function () {
    expect(VatCalc::rateAt(RATES, 'STD', '2026-11-30'))->toBe(1500);
    expect(VatCalc::rateAt(RATES, 'STD', '2026-12-01'))->toBe(1000);
    expect(VatCalc::rateAt(RATES, 'STD', '2025-12-31'))->toBeNull();
    expect(VatCalc::rateAt(RATES, 'ZERO', '2030-01-01'))->toBe(0);
    expect(VatCalc::rateAt(RATES, 'NOPE', '2026-11-30'))->toBeNull();
});

it('formats and parses percentages', function () {
    expect(VatCalc::percent(1500))->toBe('15%');
    expect(VatCalc::percent(750))->toBe('7.5%');
    expect(VatCalc::percent(125))->toBe('1.25%');
    expect(VatCalc::percent(0))->toBe('0%');
    expect(VatCalc::parsePercent('7.5'))->toBe(750);
    expect(VatCalc::parsePercent('১৫%'))->toBe(1500);
    expect(VatCalc::parsePercent('100'))->toBe(10000);
    expect(VatCalc::parsePercent('100.01'))->toBeNull();
    expect(VatCalc::parsePercent('-1'))->toBeNull();
    expect(VatCalc::parsePercent('1.234'))->toBeNull();
    expect(VatCalc::parsePercent('abc'))->toBeNull();
});

function d(string $kind, int $sub, int $vat, array $x = []): array
{
    return $x + ['kind' => $kind, 'number' => strtoupper($kind).'-1', 'issue_date' => '2026-11-05', 'party' => 'P', 'reference' => '', 'subtotal_minor' => $sub, 'vat_minor' => $vat, 'status' => 'open'];
}

it('builds the registers and the net payable', function () {
    $b = VatBooks::build([d('invoice', 100000, 15000), d('invoice', 50000, 7500), d('bill', 60000, 9000), d('expense', 20000, 3000, ['creditable' => false, 'reason' => 'no 6.3'])]);
    expect($b['sales'])->toHaveCount(2);
    expect($b['purchases'])->toHaveCount(2);
    expect($b['figures'])->toMatchArray(['sales_base_minor' => 150000, 'output_vat_minor' => 22500, 'purchase_base_minor' => 80000, 'input_vat_claimable_minor' => 9000, 'input_vat_blocked_minor' => 3000, 'input_vat_minor' => 9000, 'net_minor' => 13500]);
});

it('leaves out voided documents and shows a credit as a negative net', function () {
    $b = VatBooks::build([d('invoice', 100000, 15000, ['status' => 'void']), d('bill', 100000, 15000), d('invoice', 10000, 1500)]);
    expect($b['figures']['output_vat_minor'])->toBe(1500);
    expect($b['figures']['net_minor'])->toBe(-13500);
});

it('treats a purchase without VAT as claimable by default and a null flag as claimable', function () {
    $b = VatBooks::build([d('bill', 100, 0, ['creditable' => false]), d('bill', 100, 15, ['creditable' => null])]);
    expect($b['figures']['input_vat_claimable_minor'])->toBe(15);
    expect($b['purchases'][0]['creditable'])->toBeTrue();
});

it('finds the filing period of a date', function (string $date, string $filing, string $from, string $to) {
    expect(VatBooks::period($date, $filing))->toBe([$from, $to]);
})->with([
    ['2026-11-15', 'monthly', '2026-11-01', '2026-11-30'], ['2026-02-10', 'monthly', '2026-02-01', '2026-02-28'], ['2028-02-10', 'monthly', '2028-02-01', '2028-02-29'],
    ['2026-11-15', 'quarterly', '2026-10-01', '2026-12-31'], ['2026-01-01', 'quarterly', '2026-01-01', '2026-03-31'], ['2026-06-30', 'quarterly', '2026-04-01', '2026-06-30'],
]);

it('steps back one period, across a year end', function () {
    expect(VatBooks::previous('2026-01-01', 'monthly'))->toBe(['2025-12-01', '2025-12-31']);
    expect(VatBooks::previous('2026-01-01', 'quarterly'))->toBe(['2025-10-01', '2025-12-31']);
});

it('writes a safe CSV', function () {
    $csv = VatBooks::csv([d('bill', 12345, 1850, ['party' => '=HYPERLINK("x")', 'creditable' => false, 'reason' => 'a,b'])], true);
    expect($csv)->toStartWith("\xEF\xBB\xBF\"Number\"");
    expect($csv)->toContain('"\'=HYPERLINK(""x"")"');
    expect($csv)->toContain('"123.45","18.50","No","a,b"');
    expect(VatBooks::csv([], false))->toContain('"Customer"');
});
