<?php

use App\Audit\AuditText;

it('has Bengali wording for every rule the database can raise', function () {
    foreach (['dup_doc', 'dup_payment', 'dup_entry', 'outlier', 'backdated', 'round_manual', 'cash_large', 'numbering_gap'] as $rule) {
        expect(AuditText::RULES)->toHaveKey($rule);
        expect(AuditText::explain($rule, 123400, ['first' => 'BILL-1', 'second' => 'BILL-2', 'kind' => 'bill', 'days' => 2, 'doc' => 'BILL-1', 'date' => '2026-11-01', 'number' => 7, 'usual' => 10000, 'times' => 12.5, 'late_days' => 95, 'from' => 3, 'to' => 6]) !== 'Review needed.')->toBeTrue();
    }
});

it('puts the numbers into the sentence', function () {
    $t = AuditText::explain('dup_doc', 123400, ['first' => 'BILL-1', 'second' => 'BILL-2', 'kind' => 'bill', 'days' => 2]);
    expect(str_contains($t, 'BILL-1'))->toBeTrue();
    expect(str_contains($t, 'BILL-2'))->toBeTrue();
    expect(str_contains($t, '1,234'))->toBeTrue();
    expect(str_contains($t, '২'))->toBeTrue();
    expect(str_contains($t, 'Bills'))->toBeTrue();
});

it('shows fractional multiples with one decimal and whole ones without', function () {
    expect(str_contains(AuditText::explain('outlier', 6250100, ['number' => 'B-9', 'kind' => 'bill', 'usual' => 1250000, 'times' => 5.0]), '৫ গুণ'))->toBeTrue();
    expect(str_contains(AuditText::explain('outlier', 6250100, ['number' => 'B-9', 'kind' => 'bill', 'usual' => 1250000, 'times' => 5.2]), '৫.২ গুণ'))->toBeTrue();
});

it('falls back safely on unknown rules and missing details', function () {
    expect(AuditText::explain('mystery', 0, []))->toBe('Review needed.');
    expect(AuditText::explain('dup_doc', 100, []))->toContain('?');
});

it('describes a health row', function () {
    expect(AuditText::healthLine('overdue_bills', true, 0, 0))->toBe('OK');
    expect(AuditText::healthLine('overdue_bills', false, 2, 800000))->toBe('২টি, মোট 8,000 ৳');
    expect(AuditText::healthLine('open_high', false, 3, 0))->toBe('৩টি');
    expect(str_contains(AuditText::healthLine('books_balanced', false, 0, 500), '৳'))->toBeTrue();
    expect(AuditText::healthLine('period_locked', false, 0, 0))->toBe('No month is closed up to the recent period');
    foreach (['open_high', 'books_balanced', 'stale_bank_lines', 'overdue_bills', 'tax_undeposited', 'period_locked'] as $k) {
        expect(AuditText::HEALTH)->toHaveKey($k);
    }
});

it('accepts only a well-formed month that is not in the future', function () {
    expect(AuditText::period('2026-09', '2026-11'))->toBe('2026-09');
    expect(AuditText::period('2026-11', '2026-11'))->toBe('2026-11');
    expect(AuditText::period('2026-12', '2026-11'))->toBe('2026-11');
    expect(AuditText::period('2026-13', '2026-11'))->toBe('2026-11');
    expect(AuditText::period('x', '2026-11'))->toBe('2026-11');
    expect(AuditText::period(null, '2026-11'))->toBe('2026-11');
    expect(AuditText::period('2026-9', '2026-11'))->toBe('2026-11');
});
