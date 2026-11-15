<?php

use App\Accounting\AccReport;

function plr(string $code, string $type, int $amt): array { return ['code' => $code, 'type' => $type, 'amount_minor' => $amt, 'name' => 'x', 'name_bn' => null]; }

it('profit and loss: sections by account code and the profit steps add up', function () {
    $r = AccReport::profitLoss([plr('4100', 'income', 100000), plr('4900', 'income', -5000), plr('5000', 'expense', 30000), plr('6200', 'expense', 8000), plr('6999', 'expense', 2000), plr('7100', 'expense', 1000), plr('8000', 'expense', 500)]);
    expect($r['totals'])->toBe(['income' => 95000, 'cogs' => 30000, 'gross' => 65000, 'opex' => 10500, 'operating' => 54500, 'nonop' => 1000, 'net' => 53500]);   // 8000 (custom code) counts as operating
    expect(count($r['income']))->toBe(2);
    expect(count($r['opex']))->toBe(3);
    expect(AccReport::profitLoss([])['totals']['net'])->toBe(0);
    expect(AccReport::profitLoss([plr('6200', 'expense', 700)])['totals']['net'])->toBe(-700);                  // a loss is negative
});

it('balance sheet: earnings join equity and the equation is checked', function () {
    $rows = [['section' => 'asset', 'amount_minor' => 700], ['section' => 'liability', 'amount_minor' => 100], ['section' => 'equity', 'amount_minor' => 500], ['section' => 'earnings', 'amount_minor' => 100]];
    $b = AccReport::balanceSheet($rows);
    expect($b['totals'])->toBe(['assets' => 700, 'liabilities' => 100, 'equity' => 600, 'liabilities_equity' => 700]);
    expect($b['balanced'])->toBeTrue();
    $rows[0]['amount_minor'] = 701;
    expect(AccReport::balanceSheet($rows)['balanced'])->toBeFalse();
    expect(AccReport::balanceSheet([])['balanced'])->toBeTrue();
});

it('cash flow: sections, net and the opening-to-closing check', function () {
    $rows = [['section' => 'opening', 'amount_minor' => 500], ['section' => 'operating', 'amount_minor' => 200], ['section' => 'operating', 'amount_minor' => -50], ['section' => 'investing', 'amount_minor' => -300], ['section' => 'financing', 'amount_minor' => 90], ['section' => 'closing', 'amount_minor' => 440]];
    $c = AccReport::cashFlow($rows);
    expect($c['totals'])->toBe(['operating' => 150, 'investing' => -300, 'financing' => 90, 'net' => -60]);
    expect($c['reconciled'])->toBeTrue();
    $rows[5]['amount_minor'] = 441;
    expect(AccReport::cashFlow($rows)['reconciled'])->toBeFalse();
});

it('period presets follow the Bangladesh fiscal year and never leave the calendar', function () {
    expect(AccReport::range('month', '2026-11-15'))->toBe(['2026-11-01', '2026-11-15']);
    expect(AccReport::range('last_month', '2026-03-10'))->toBe(['2026-02-01', '2026-02-28']);
    expect(AccReport::range('last_month', '2026-01-10'))->toBe(['2025-12-01', '2025-12-31']);
    expect(AccReport::range('fiscal_year', '2026-11-15'))->toBe(['2026-07-01', '2026-11-15']);
    expect(AccReport::range('fiscal_year', '2026-07-01'))->toBe(['2026-07-01', '2026-07-01']);
    expect(AccReport::range('fiscal_year', '2026-06-30'))->toBe(['2025-07-01', '2026-06-30']);
    expect(AccReport::range('fiscal_year', '2026-03-15'))->toBe(['2025-07-01', '2026-03-15']);
    expect(AccReport::range('last_fiscal_year', '2026-11-15'))->toBe(['2025-07-01', '2026-06-30']);
    expect(AccReport::range('last_fiscal_year', '2026-03-15'))->toBe(['2024-07-01', '2025-06-30']);
    expect(AccReport::range('calendar_year', '2026-11-15'))->toBe(['2026-01-01', '2026-11-15']);
    expect(AccReport::range('nonsense', '2026-11-15'))->toBe(['2026-11-01', '2026-11-15']);
});

it('day(): only real dates from 2000 on are accepted', function () {
    expect(AccReport::day('2026-02-28', 'F'))->toBe('2026-02-28');
    foreach (['2026-02-30', '1999-12-31', '26-1-1', '', null, ['x'], '2026-13-01', "2026-01-01\n"] as $bad) {
        expect(AccReport::day($bad, 'F'))->toBe('F');
    }
});
