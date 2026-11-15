<?php

use App\Vat\TaxItems;

const TRULES = [
    ['kind' => 'tds', 'code' => 'TDS5', 'rate_bp' => 500, 'effective_from' => '2026-07-01', 'effective_to' => '2026-11-30'],
    ['kind' => 'tds', 'code' => 'TDS5', 'rate_bp' => 700, 'effective_from' => '2026-12-01', 'effective_to' => null],
    ['kind' => 'vds', 'code' => 'V15', 'rate_bp' => 1500, 'effective_from' => '2026-01-01', 'effective_to' => null],
];

it('works out the amount from the rule when it is left blank', function () {
    $r = TaxItems::parse([['kind' => 'tds', 'rule' => 'TDS5', 'base' => '1,000', 'amount' => '', 'cert' => ' C1 ']], 100000, TRULES, '2026-11-15');
    expect($r['error'])->toBeNull();
    expect($r['items'])->toBe([['kind' => 'tds', 'rule_code' => 'TDS5', 'base_minor' => 100000, 'rate_bp' => 500, 'amount_minor' => 5000, 'cert_no' => 'C1']]);
    expect($r['withheld_minor'])->toBe(5000);
});

it('uses the rate in force on the payment date', function () {
    expect(TaxItems::parse([['kind' => 'tds', 'rule' => 'TDS5', 'base' => '', 'amount' => '']], 100000, TRULES, '2026-12-05')['items'][0]['amount_minor'])->toBe(7000);
    expect(TaxItems::parse([['kind' => 'tds', 'rule' => 'TDS5', 'base' => '', 'amount' => '']], 100000, TRULES, '2026-06-30')['error'])->toContain('Active None');
});

it('lets the person confirm a different amount and records the effective rate', function () {
    $r = TaxItems::parse([['kind' => 'vds', 'rule' => '', 'base' => '1000', 'amount' => '30']], 100000, TRULES, '2026-11-15');
    expect($r['items'][0])->toMatchArray(['rule_code' => null, 'amount_minor' => 3000, 'rate_bp' => 300, 'cert_no' => null]);
    $own = TaxItems::parse([['kind' => 'vds', 'rule' => 'V15', 'base' => '1000', 'amount' => '149.99']], 100000, TRULES, '2026-11-15');
    expect($own['items'][0]['amount_minor'])->toBe(14999);          // typed amount wins over the rule
    expect($own['items'][0]['rate_bp'])->toBe(1500);
});

it('skips empty rows and bengali digits work', function () {
    $r = TaxItems::parse([['kind' => '', 'base' => '', 'amount' => '', 'rule' => ''], ['kind' => 'tds', 'rule' => '', 'base' => '', 'amount' => '৫০']], 100000, TRULES, '2026-11-15');
    expect($r['items'])->toHaveCount(1);
    expect($r['items'][0]['amount_minor'])->toBe(5000);
});

it('refuses bad rows with a Bengali reason', function (array $rows, string $word) {
    expect(TaxItems::parse($rows, 100000, TRULES, '2026-11-15')['error'])->toContain($word);
})->with([
    [[], 'অন্তত একitems'],
    [[['kind' => '', 'base' => '5', 'amount' => '1', 'rule' => '']], 'Type'],
    [[['kind' => 'gst', 'base' => '5', 'amount' => '1', 'rule' => '']], 'Type'],
    [[['kind' => 'tds', 'base' => 'abc', 'amount' => '1', 'rule' => '']], 'Base'],
    [[['kind' => 'tds', 'base' => '', 'amount' => '', 'rule' => '']], 'rules orছুন'],
    [[['kind' => 'tds', 'base' => '', 'amount' => '0', 'rule' => '']], 'Amount'],
    [[['kind' => 'tds', 'base' => '', 'amount' => '-3', 'rule' => '']], 'Amount'],
    [[['kind' => 'tds', 'base' => '', 'amount' => '1000', 'rule' => '']], 'Low হতে হবে'],
    [[['kind' => 'tds', 'base' => '', 'amount' => '600', 'rule' => ''], ['kind' => 'vds', 'base' => '', 'amount' => '400', 'rule' => '']], 'Low হতে হবে'],
    [[['kind' => 'tds', 'base' => '', 'amount' => '1', 'rule' => 'NOPE']], 'Active None'],
    [[['kind' => 'vds', 'base' => '', 'amount' => '1', 'rule' => 'TDS5']], 'Active None'],           // the rule must match the kind
]);

it('allows at most five lines', function () {
    $row = ['kind' => 'tds', 'base' => '', 'amount' => '1', 'rule' => ''];
    expect(TaxItems::parse(array_fill(0, 5, $row), 100000, TRULES, '2026-11-15')['error'])->toBeNull();
    expect(TaxItems::parse(array_fill(0, 6, $row), 100000, TRULES, '2026-11-15')['error'])->toContain('৫items');
});
