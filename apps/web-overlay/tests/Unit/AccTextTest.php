<?php

use App\Accounting\AccText;

it('amounts: plain, comma, Bengali digits and at most two decimals become poisha; anything else is refused', function () {
    expect(AccText::toMinor('1500'))->toBe(150000);
    expect(AccText::toMinor('1,234.5'))->toBe(123450);
    expect(AccText::toMinor('১২৩৪.৫০'))->toBe(123450);
    expect(AccText::toMinor('0.05'))->toBe(5);
    foreach (['', '0', '0.00', '-5', '1e5', '1.234', 'abc', '1..2', '12345678901234'] as $bad) {
        expect(AccText::toMinor($bad) === null)->toBeTrue();
    }
});

it('form rows: blank rows are skipped, each line is debit XOR credit, bad input gives a Bengali message', function () {
    $a = str_repeat('a', 26);
    $b = str_repeat('b', 26);
    $ok = AccText::lines([['account_id' => $a, 'debit' => '100', 'credit' => '', 'memo' => ''], ['account_id' => $b, 'debit' => '', 'credit' => '100.5', 'memo' => ' x '], ['account_id' => '', 'debit' => '', 'credit' => '']]);
    expect($ok['error'] === null)->toBeTrue();
    expect($ok['lines'])->toBe([['account_id' => $a, 'debit_minor' => 10000, 'credit_minor' => 0, 'memo' => null], ['account_id' => $b, 'debit_minor' => 0, 'credit_minor' => 10050, 'memo' => 'x']]);
    expect(AccText::lines([['account_id' => $a, 'debit' => '1', 'credit' => '1']])['error'] !== null)->toBeTrue();       // both sides
    expect(AccText::lines([['account_id' => $a, 'debit' => '', 'credit' => '']])['error'] !== null)->toBeTrue();         // neither
    expect(AccText::lines([['account_id' => '', 'debit' => '5']])['error'] !== null)->toBeTrue();                          // no account
    expect(AccText::lines([['account_id' => 'short', 'debit' => '5']])['error'] !== null)->toBeTrue();
    expect(AccText::lines([['account_id' => $a, 'debit' => 'x']])['error'] !== null)->toBeTrue();
});

it('every database refusal has a message and success has none', function () {
    foreach (['bad_memo', 'bad_date', 'locked', 'too_few_lines', 'bad_line', 'unbalanced', 'bad_account', 'inactive_account', 'not_postable'] as $r) {
        expect(AccText::postError($r) !== null)->toBeTrue();
    }
    expect(AccText::postError('ok') === null)->toBeTrue();
    expect(AccText::postError('???') !== null)->toBeTrue();
    foreach (['already_reversed', 'is_reversal', 'locked', 'bad_date'] as $r) {
        expect(AccText::reverseError($r) !== null)->toBeTrue();
    }
    foreach (['not_forward', 'bad_date', 'no_reason', 'not_back', 'not_locked'] as $r) {
        expect(AccText::lockError($r) !== null)->toBeTrue();
    }
    expect(AccText::money(150050))->toBe('1,500.50');
    expect(AccText::money(150000))->toBe('1,500');
});

it('document lines: skips blank rows, needs an account and a good amount, and every database refusal is worded', function () {
    $a = str_repeat('a', 26);
    $ok = AccText::docLines([['account_id' => $a, 'description' => ' ডিজাইন ', 'amount' => '1,500'], ['account_id' => '', 'description' => '', 'amount' => '']]);
    expect($ok)->toBe(['lines' => [['account_id' => $a, 'description' => 'Design', 'amount_minor' => 150000]], 'error' => null]);
    expect(AccText::docLines([['account_id' => $a, 'amount' => '']])['error'] !== null)->toBeTrue();
    expect(AccText::docLines([['account_id' => $a, 'amount' => '0']])['error'] !== null)->toBeTrue();
    expect(AccText::docLines([['account_id' => '', 'amount' => '5']])['error'] !== null)->toBeTrue();
    expect(AccText::docLines([])['error'] !== null)->toBeTrue();
    foreach (['bad_party', 'bad_lines', 'bad_line_account', 'bad_vat', 'bad_due', 'no_system_account', 'bad_pay_account', 'locked', 'bad_date'] as $r) {
        expect(AccText::docError($r) !== null)->toBeTrue();
    }
    foreach (['bad_amount', 'bad_account', 'not_payable', 'overpay', 'locked'] as $r) {
        expect(AccText::payError($r) !== null)->toBeTrue();
    }
    foreach (['already_void', 'has_payments', 'locked'] as $r) {
        expect(AccText::voidError($r) !== null)->toBeTrue();
    }
    expect(AccText::docError('ok') === null && AccText::payError('ok') === null && AccText::voidError('ok') === null)->toBeTrue();
    expect(AccText::bucketBn('90+'))->toBe('90+ days');
});

it('bank messages: success has none, each refusal has one, a journal refusal reuses the posting message', function () {
    expect(AccText::bankImportError('ok'))->toBeNull();
    expect(str_contains((string) AccText::bankImportError('bad_row', 7), '7'))->toBeTrue();
    foreach (['bad_account', 'bad_rows', 'weird'] as $r) {
        expect(AccText::bankImportError($r) !== null)->toBeTrue();
    }
    expect(AccText::bankMatchError('ok'))->toBeNull();
    foreach (['not_open', 'not_matched', 'not_ignored', 'bad_journal_line', 'taken', 'bad_account', 'locked', 'bad_date', 'weird'] as $r) {
        expect(AccText::bankMatchError($r) !== null)->toBeTrue();
    }
    expect(AccText::bankMatchError('locked'))->toBe(AccText::postError('locked'));
});

it('asset and inventory messages: success has none, every refusal has one', function () {
    foreach (['assetError' => ['bad_input', 'bad_account', 'no_system_account'], 'depreciationError' => ['nothing_to_do', 'bad_month', 'locked'],
        'disposeError' => ['not_active', 'bad_date', 'bad_proceeds', 'depreciate_first', 'bad_pay_account', 'no_system_account', 'locked'],
        'costError' => ['bad_cost', 'bad_date'], 'revalueError' => ['no_difference', 'no_system_account', 'bad_account', 'bad_date', 'locked']] as $fn => $codes) {
        expect(AccText::$fn('ok'))->toBeNull();
        foreach ($codes as $c) {
            expect(AccText::$fn($c) !== null)->toBeTrue();
        }
        expect(AccText::$fn('weird') !== null)->toBeTrue();
    }
    expect(AccText::depreciationError('locked'))->toBe(AccText::postError('locked'));
});

it('auto-post and courier settlement messages: success has none, every refusal has one, failures are explained in Bengali', function () {
    expect(AccText::autopostError('ok'))->toBeNull();
    foreach (['not_seeded', 'no_system_account', 'weird'] as $c) {
        expect(AccText::autopostError($c) !== null)->toBeTrue();
    }
    expect(AccText::settleError('ok'))->toBeNull();
    foreach (['bad_input', 'bad_date', 'bad_account', 'no_system_account', 'exceeds_receivable', 'already_void', 'locked', 'weird'] as $c) {
        expect(AccText::settleError($c) !== null)->toBeTrue();
    }
    expect(AccText::settleError('locked'))->toBe(AccText::postError('locked'));
    expect(AccText::autopostFailure('locked'))->toContain('Lock');
    expect(AccText::autopostFailure('error:P0001'))->toContain('P0001');
    expect(AccText::autopostFailure('no_system_account') !== '')->toBeTrue();
    expect(AccText::autopostFailure('unbalanced'))->toBe(AccText::postError('unbalanced'));
    expect(AccText::autopostFailure(null))->toBe('');
    expect(AccText::autopostFailure('zzz') !== '')->toBeTrue();
    expect(AccText::courierBn('redx'))->toBe('RedX');
    expect(AccText::courierBn('other'))->toBe('other');
});
