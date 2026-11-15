<?php

use App\Accounting\BankCsv;

it('amounts: commas, signs, brackets, Dr/Cr, taka marks and Bengali digits become signed poisha; junk is false; blank is null', function () {
    expect(BankCsv::amount('1,234.50'))->toBe(123450);
    expect(BankCsv::amount('-500'))->toBe(-50000);
    expect(BankCsv::amount('(500.5)'))->toBe(-50050);
    expect(BankCsv::amount('500 Dr'))->toBe(-50000);
    expect(BankCsv::amount('500.00 CR'))->toBe(50000);
    expect(BankCsv::amount('৳ ১,২৩৪'))->toBe(123400);
    expect(BankCsv::amount('BDT 99'))->toBe(9900);
    expect(BankCsv::amount('+12'))->toBe(1200);
    expect(BankCsv::amount('0'))->toBe(0);
    expect(BankCsv::amount('') === null && BankCsv::amount('-') === null)->toBeTrue();
    foreach (['abc', '1.234', '1e5', '12-', '--5', '(5', '1,2,3x'] as $bad) {
        expect(BankCsv::amount($bad))->toBeFalse();
    }
});

it('dates are day-first, accept month names and times, and refuse impossible days', function () {
    expect(BankCsv::date('03/11/2026'))->toBe('2026-11-03');
    expect(BankCsv::date('3-11-26'))->toBe('2026-11-03');
    expect(BankCsv::date('2026-11-03'))->toBe('2026-11-03');
    expect(BankCsv::date('03.11.2026 14:05:33'))->toBe('2026-11-03');
    expect(BankCsv::date('03-Nov-2026'))->toBe('2026-11-03');
    expect(BankCsv::date('3 November 2026'))->toBe('2026-11-03');
    expect(BankCsv::date('২০২৬-১১-০৩'))->toBe('2026-11-03');
    expect(BankCsv::date('2026-11-03T10:00:00'))->toBe('2026-11-03');
    foreach (['31/02/2026', '00/01/2026', '13/13/2026', '1999-12-31', 'x', '', '03/11'] as $bad) {
        expect(BankCsv::date($bad) === null)->toBeTrue();
    }
});

it('debit/credit layout: credit is money in, debit money out; notes above the header and totals below are skipped', function () {
    $csv = "Account Statement\nBranch: Gulshan\n\nDate,Particulars,Cheque No,Withdrawal,Deposit,Balance\n01/11/2026,Opening,,,,\"10,000.00\"\n02/11/2026,\"Cash deposit, Dhanmondi\",CD1,,\"2,500.00\",\"12,500.00\"\n03/11/2026,ATM,,500.00,,\"12,000.00\"\n,Total,,500.00,2500.00,\n";
    $r = BankCsv::parse($csv);
    expect($r['ok'])->toBeTrue();
    expect(array_column($r['rows'], 'amount_minor'))->toBe([250000, -50000]);
    expect($r['rows'][0])->toBe(['date' => '2026-11-02', 'description' => 'Cash deposit, Dhanmondi', 'reference' => 'CD1', 'amount_minor' => 250000, 'balance_minor' => 1250000]);
    expect($r['rows'][1]['reference'] === null)->toBeTrue();
    expect($r['skipped'])->toBe(2);                                                                     // the opening row (no money) and the totals row
});

it('single signed amount layout, semicolons, BOM, and the invert switch for banks that print withdrawals as positive', function () {
    $csv = "\xEF\xBB\xBFDate;Description;Amount;Balance\n2026-11-02;bKash cash in;1000;5000\n2026-11-03;Send money;-250;4750\n";
    $r = BankCsv::parse($csv);
    expect(array_column($r['rows'], 'amount_minor'))->toBe([100000, -25000]);
    expect(array_column(BankCsv::parse($csv, [], true)['rows'], 'amount_minor'))->toBe([-100000, 25000]);
});

it('problems are reported per line and never silently dropped; unknown headers ask the person to choose', function () {
    $r = BankCsv::parse("Date,Description,Amount\n02/11/2026,ok,100\nnot a date,bad,5\n03/11/2026,bad amount,12x\n04/11/2026,\"two\",,\n");
    expect($r['ok'])->toBeFalse();
    expect(count($r['errors']))->toBe(2);
    expect(count($r['rows']))->toBe(1);
    expect($r['skipped'])->toBe(1);                                                                    // the row with a date but no amount is counted, not hidden
    $u = BankCsv::parse("a,b,c\n02/11/2026,hello,100\n");
    expect($u['ok'])->toBeFalse();
    expect($u['error'] !== null && $u['headers'] === ['a', 'b', 'c'])->toBeTrue();
    $p = BankCsv::parse("a,b,c\n02/11/2026,hello,100\n", ['date' => 0, 'description' => 1, 'amount' => 2]);
    expect($p['ok'])->toBeTrue();
    expect($p['rows'][0]['amount_minor'])->toBe(10000);
    expect(count(BankCsv::parse("Date,Debit,Credit\n02/11/2026,5,5\n")['errors']))->toBe(1);          // both sides filled
    expect(str_contains((string) BankCsv::parse("\xFF\xFE")['error'], 'UTF-8'))->toBeTrue();             // not UTF-8
    expect(str_contains((string) BankCsv::parse(str_repeat('x', 2_000_001))['error'], 'MB'))->toBeTrue();
    $many = "Date,Amount\n".str_repeat("02/11/2026,5\n", 5001);
    expect(str_contains((string) BankCsv::parse($many)['error'], '৫০০০'))->toBeTrue();                    // one over the limit
    expect(count(BankCsv::parse("Date,Amount\n".str_repeat("02/11/2026,5\n", 5000))['rows']))->toBe(5000);
    expect(BankCsv::parse("Date,Amount\n")['ok'])->toBeFalse();
});
