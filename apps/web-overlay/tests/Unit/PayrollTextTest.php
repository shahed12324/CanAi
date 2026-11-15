<?php

use App\Payroll\PayrollText;

it('names the month in Bengali', function () {
    expect(PayrollText::month('2026-11'))->toBe('November 2026');
    expect(PayrollText::month('2027-01'))->toBe('January 2027');
    expect(PayrollText::month('bad'))->toBe('bad');
});

it('has a message for every result the database can return', function () {
    foreach (['bad_input', 'bad_date', 'code_taken', 'overlap', 'already_ended', 'bad_period', 'exists', 'nothing', 'negative_net', 'not_draft', 'no_line', 'no_item', 'not_posted', 'bad_account', 'nothing_to_pay', 'no_system_account', 'paid', 'already_void'] as $r) {
        expect(PayrollText::error($r) !== null && PayrollText::error($r) !== 'The entry could not be made.')->toBeTrue();
    }
    expect(PayrollText::error('ok'))->toBeNull();
    expect(PayrollText::error('something_new') !== null)->toBeTrue();
    expect(str_contains((string) PayrollText::error('locked'), 'Closed'))->toBeTrue();
});

it('labels one-off items with their kind and leaves structure lines plain', function () {
    expect(PayrollText::itemLabel(['label' => 'Basic salary', 'src' => 'structure']))->toBe('Basic salary');
    expect(PayrollText::itemLabel(['label' => 'Basic salary']))->toBe('Basic salary');
    expect(PayrollText::itemLabel(['label' => 'ঈদ', 'src' => 'bonus']))->toBe('Bonus: ঈদ');
    expect(PayrollText::itemLabel(['label' => 'অগ্রিম', 'src' => 'deduction']))->toBe('One-time deduction: অগ্রিম');
});

it('has a status name for each run state', function () {
    foreach (['draft', 'posted', 'paid', 'void'] as $s) {
        expect(PayrollText::STATUS)->toHaveKey($s);
    }
});
