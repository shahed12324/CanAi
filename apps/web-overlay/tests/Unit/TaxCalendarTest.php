<?php

use App\Vat\TaxCalendar;

function ob(array $o = []): array
{
    return array_merge(['id' => 'o1', 'label' => 'VAT', 'kind' => 'vat_return', 'freq' => 'monthly', 'due_day' => 15, 'due_month' => null, 'once_date' => null, 'remind_days' => 7, 'active' => true], $o);
}

it('lists monthly due dates inside the window, ends included', function () {
    expect(TaxCalendar::dueDates(ob(), '2026-10-15', '2026-12-15'))->toBe(['2026-10-15', '2026-11-15', '2026-12-15']);
    expect(TaxCalendar::dueDates(ob(), '2026-10-16', '2026-12-14'))->toBe(['2026-11-15']);
    expect(TaxCalendar::dueDates(ob(), '2026-12-01', '2026-11-01'))->toBe([]);
});

it('puts day 29-31 on the last day of a shorter month', function () {
    expect(TaxCalendar::dueDates(ob(['due_day' => 31]), '2026-01-01', '2026-04-30'))->toBe(['2026-01-31', '2026-02-28', '2026-03-31', '2026-04-30']);
    expect(TaxCalendar::dueDates(ob(['due_day' => 30]), '2024-02-01', '2024-02-29'))->toBe(['2024-02-29']);
});

it('quarterly follows the cycle start month', function () {
    expect(TaxCalendar::dueDates(ob(['freq' => 'quarterly', 'due_month' => 3, 'due_day' => 15]), '2026-01-01', '2026-12-31'))->toBe(['2026-03-15', '2026-06-15', '2026-09-15', '2026-12-15']);
    expect(TaxCalendar::dueDates(ob(['freq' => 'quarterly', 'due_month' => 1, 'due_day' => 10]), '2026-01-01', '2026-12-31'))->toBe(['2026-01-10', '2026-04-10', '2026-07-10', '2026-10-10']);
});

it('yearly and once give one date', function () {
    expect(TaxCalendar::dueDates(ob(['freq' => 'yearly', 'due_month' => 11, 'due_day' => 30]), '2026-01-01', '2027-12-31'))->toBe(['2026-11-30', '2027-11-30']);
    expect(TaxCalendar::dueDates(ob(['freq' => 'once', 'once_date' => '2026-12-01', 'due_day' => null]), '2026-11-01', '2026-12-31'))->toBe(['2026-12-01']);
    expect(TaxCalendar::dueDates(ob(['freq' => 'once', 'once_date' => '2027-01-01', 'due_day' => null]), '2026-11-01', '2026-12-31'))->toBe([]);
});

it('returns nothing for a broken row instead of failing', function () {
    expect(TaxCalendar::dueDates(ob(['due_day' => 0]), '2026-01-01', '2026-12-31'))->toBe([]);
    expect(TaxCalendar::dueDates(ob(['due_day' => null]), '2026-01-01', '2026-12-31'))->toBe([]);
    expect(TaxCalendar::dueDates(ob(['freq' => 'weekly']), '2026-01-01', '2026-12-31'))->toBe([]);
    expect(TaxCalendar::dueDates(ob(['freq' => 'once', 'once_date' => null]), '2026-01-01', '2026-12-31'))->toBe([]);
});

it('marks each row overdue, soon, upcoming or done', function () {
    $rows = TaxCalendar::agenda([ob()], ['o1|2026-10-15' => '2026-10-14'], '2026-11-10', 40, 40);
    $by = array_column($rows, 'status', 'due');
    expect($by)->toBe(['2026-10-15' => 'done', '2026-11-15' => 'due_soon', '2026-12-15' => 'upcoming']);
    $rows = TaxCalendar::agenda([ob()], [], '2026-11-10', 40, 40);
    expect(array_column($rows, 'status', 'due')['2026-10-15'])->toBe('overdue');
    expect($rows[0]['days'])->toBe(-26);
});

it('treats the due day itself as due soon and the day after as overdue', function () {
    $today = array_column(TaxCalendar::agenda([ob()], [], '2026-11-15', 0, 0), 'status', 'due');
    expect($today)->toBe(['2026-11-15' => 'due_soon']);
    $next = array_column(TaxCalendar::agenda([ob()], [], '2026-11-16', 1, 0), 'status', 'due');
    expect($next)->toBe(['2026-11-15' => 'overdue']);
});

it('respects the reminder window', function () {
    expect(array_column(TaxCalendar::agenda([ob(['remind_days' => 3])], [], '2026-11-12', 0, 5), 'status', 'due'))->toBe(['2026-11-15' => 'due_soon']);
    expect(array_column(TaxCalendar::agenda([ob(['remind_days' => 2])], [], '2026-11-12', 0, 5), 'status', 'due'))->toBe(['2026-11-15' => 'upcoming']);
});

it('skips switched-off obligations and sorts by date then name', function () {
    $rows = TaxCalendar::agenda([ob(['id' => 'a', 'active' => false]), ob(['id' => 'b', 'label' => 'ঘ']), ob(['id' => 'c', 'label' => 'ক', 'due_day' => 20]), ob(['id' => 'd', 'label' => 'ক'])], [], '2026-11-01', 0, 30);
    expect(array_map(fn ($r) => $r['id'].$r['due'], $rows))->toBe(['d2026-11-15', 'b2026-11-15', 'c2026-11-20']);
});

it('counts by status', function () {
    $rows = TaxCalendar::agenda([ob()], ['o1|2026-10-15' => '2026-10-15'], '2026-11-10', 40, 40);
    expect(TaxCalendar::counts($rows))->toBe(['overdue' => 0, 'due_soon' => 1, 'upcoming' => 1, 'done' => 1]);
});
