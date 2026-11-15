<?php

use App\Productivity\DailyPlan;
use App\Productivity\ProductivityText;
use App\Productivity\TaskInput;

function ptask(string $id, int $prio = 2, ?string $due = null, ?string $remind = null): array
{
    return ['id' => $id, 'title' => "t-$id", 'priority' => $prio, 'due_on' => $due, 'remind_at' => $remind];
}

it('reads a task form and keeps the reminder as an exact moment in the person\'s zone', function () {
    $r = TaskInput::parse(['title' => "  বিল   দিতে\n হবে ", 'details' => ' x ', 'priority' => '1', 'due_on' => '2026-11-30', 'remind_at' => '2026-11-29T09:30'], 'Asia/Dhaka');
    expect($r['error'])->toBeNull();
    expect($r['title'])->toBe('বিল দিতে হবে');
    expect($r['details'])->toBe('x');
    expect($r['priority'])->toBe(1);
    expect($r['due'])->toBe('2026-11-30');
    expect($r['remind'])->toBe('2026-11-29 09:30:00+06:00');
    $u = TaskInput::parse(['title' => 'x', 'remind_at' => '2026-11-29T09:30'], 'UTC');
    expect($u['remind'])->toBe('2026-11-29 09:30:00+00:00');
});

it('treats empty date and time as none and defaults to normal priority', function () {
    $r = TaskInput::parse(['title' => 'x', 'due_on' => '', 'remind_at' => ''], 'Asia/Dhaka');
    expect($r['error'])->toBeNull();
    expect($r['due'])->toBeNull();
    expect($r['remind'])->toBeNull();
    expect($r['priority'])->toBe(2);
});

it('refuses bad input with a named reason', function () {
    $cases = [
        'empty' => ['title' => '   '],
        'too_long' => ['title' => str_repeat('ক', 201)],
        'bad_priority' => ['title' => 'x', 'priority' => '9'],
        'bad_priority_4' => ['title' => 'x', 'priority' => '4'],
        'bad_priority_0' => ['title' => 'x', 'priority' => '0'],
        'bad_date' => ['title' => 'x', 'due_on' => '2026-02-30'],
        'bad_time' => ['title' => 'x', 'remind_at' => '2026-11-29 09:30'],
    ];
    foreach ($cases as $reason => $in) {
        expect(TaskInput::parse($in, 'Asia/Dhaka')['error'])->toBe(str_replace(['_4', '_0'], '', $reason));
    }
    expect(TaskInput::parse(['title' => 'x', 'details' => str_repeat('ক', 2001)], 'Asia/Dhaka')['error'])->toBe('too_long');
    expect(TaskInput::parse(['title' => str_repeat('ক', 200), 'details' => str_repeat('খ', 2000)], 'Asia/Dhaka')['error'])->toBeNull();
    expect(TaskInput::parse(['title' => 'x', 'remind_at' => '2026-13-01T10:00'], 'Asia/Dhaka')['error'])->toBe('bad_time');
    expect(TaskInput::parse(['title' => 'x', 'remind_at' => '2026-11-29T25:00'], 'Asia/Dhaka')['error'])->toBe('bad_time');
});

it('falls back to Dhaka for an unknown zone and shows stored times in the person\'s zone', function () {
    expect(TaskInput::parse(['title' => 'x', 'remind_at' => '2026-11-29T09:30'], 'Mars/Base')['remind'])->toBe('2026-11-29 09:30:00+06:00');
    expect(TaskInput::toLocalInput('2026-11-29 03:30:00+00', 'Asia/Dhaka'))->toBe('2026-11-29T09:30');
    expect(TaskInput::toLocalInput(null, 'Asia/Dhaka'))->toBe('');
    expect(TaskInput::toLocalInput('', 'Asia/Dhaka'))->toBe('');
});

it('has a message for every answer the database or the form can give', function () {
    foreach (['empty', 'too_long', 'bad_priority', 'bad_date', 'bad_time', 'bad_input', 'limit', 'not_found'] as $r) {
        expect(ProductivityText::error($r) !== null && ProductivityText::error($r) !== '')->toBeTrue();
    }
    expect(ProductivityText::error('ok'))->toBeNull();
    expect(ProductivityText::error('something_new'))->toBe('Could not be done.');
});

it('plans the day: overdue first, then today, reminders, the next three days, then important undated ones', function () {
    $open = [
        ptask('anytime-hi', 1), ptask('anytime-lo', 3), ptask('later', 1, '2026-12-25'),
        ptask('soon', 1, '2026-11-06'), ptask('today-lo', 3, '2026-11-04'), ptask('today-hi', 1, '2026-11-04'),
        ptask('over-new', 2, '2026-11-03'), ptask('over-old', 3, '2026-10-20'),
        ptask('remind', 2, null, '2026-11-04 15:00:00+06'),
    ];
    $p = DailyPlan::build($open, '2026-11-04', 'Asia/Dhaka');
    expect(array_column($p['overdue'], 'id'))->toBe(['over-old', 'over-new']);
    expect(array_column($p['today'], 'id'))->toBe(['today-hi', 'today-lo']);
    expect(array_column($p['reminders'], 'id'))->toBe(['remind']);
    expect(array_column($p['soon'], 'id'))->toBe(['soon']);
    expect(array_column($p['anytime'], 'id'))->toBe(['anytime-hi']);
    expect(array_column($p['focus'], 'id'))->toBe(['over-old', 'over-new', 'today-hi', 'today-lo', 'remind']);
});

it('caps the focus list and leaves far-off and low-priority undated tasks out', function () {
    $open = [];
    for ($i = 1; $i <= 8; $i++) {
        $open[] = ptask("d$i", 2, '2026-11-04');
    }
    $p = DailyPlan::build($open + [99 => ptask('far', 1, '2027-01-01'), 98 => ptask('none', 3)], '2026-11-04', 'Asia/Dhaka');
    expect(count($p['focus']))->toBe(5);
    expect(count($p['today']))->toBe(8);
    expect($p['soon'] === [] && $p['anytime'] === [])->toBeTrue();
});

it('counts the reminder day in the person\'s zone, not UTC', function () {
    // 2026-11-04 20:00 UTC is already 2026-11-05 02:00 in Dhaka
    $open = [ptask('late', 2, null, '2026-11-04 20:00:00+00')];
    expect(count(DailyPlan::build($open, '2026-11-05', 'Asia/Dhaka')['reminders']))->toBe(1);
    expect(count(DailyPlan::build($open, '2026-11-04', 'Asia/Dhaka')['reminders']))->toBe(0);
});

it('does not list a task due today twice because it also has a reminder today', function () {
    $p = DailyPlan::build([ptask('x', 2, '2026-11-04', '2026-11-04 08:00:00+06')], '2026-11-04', 'Asia/Dhaka');
    expect(count($p['today']))->toBe(1);
    expect(count($p['reminders']))->toBe(0);
    expect(count($p['focus']))->toBe(1);
});

it('puts a task with an overdue date and a reminder today under overdue only', function () {
    $p = DailyPlan::build([ptask('x', 2, '2026-11-01', '2026-11-04 08:00:00+06')], '2026-11-04', 'Asia/Dhaka');
    expect(count($p['overdue']))->toBe(1);
    expect(count($p['focus']))->toBe(1);
});

it('gives an empty plan for no tasks', function () {
    $p = DailyPlan::build([], '2026-11-04', 'Asia/Dhaka');
    expect($p['focus'])->toBe([]);
});

it('sorts the groups: near dates by date then importance, reminders by time, undated by importance', function () {
    $open = [
        ptask('s2', 2, '2026-11-07'), ptask('s1', 3, '2026-11-05'), ptask('s1b', 1, '2026-11-05'),
        ptask('r2', 2, null, '2026-11-04 18:00:00+06'), ptask('r1', 2, null, '2026-11-04 09:00:00+06'),
        ptask('a2', 1), ptask('a1', 1),
    ];
    $p = DailyPlan::build($open, '2026-11-04', 'Asia/Dhaka');
    expect(array_column($p['soon'], 'id'))->toBe(['s1b', 's1', 's2']);
    expect(array_column($p['reminders'], 'id'))->toBe(['r1', 'r2']);
    expect(array_column($p['anytime'], 'id'))->toBe(['a1', 'a2']);
});

it('counts exactly the next three days as soon', function () {
    $p = DailyPlan::build([ptask('d3', 2, '2026-11-07'), ptask('d4', 2, '2026-11-08')], '2026-11-04', 'Asia/Dhaka');
    expect(array_column($p['soon'], 'id'))->toBe(['d3']);
});
