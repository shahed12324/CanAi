<?php

declare(strict_types=1);

use App\Automation\NextRun;
use App\Automation\ScheduleParser as P;

function spec(string $s): array
{
    $r = P::parse($s);
    expect($r)->toHaveKey('spec');

    return $r['spec'];
}

function utc(string $local, string $tz = 'Asia/Dhaka'): DateTimeImmutable
{
    return new DateTimeImmutable($local, new DateTimeZone($tz));
}

function at(array $s, string $from): string
{
    return NextRun::after($s, 'Asia/Dhaka', utc($from))->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('Y-m-d H:i');
}

it('reads Bangla and English schedules', function () {
    expect(spec('প্রতিদিন সকাল ৯টায়'))->toBe(['kind' => 'daily', 'time' => '09:00']);
    expect(spec('every day at 9pm'))->toBe(['kind' => 'daily', 'time' => '21:00']);
    expect(spec('রাত ১১টায় প্রতিদিন'))->toBe(['kind' => 'daily', 'time' => '23:00']);
    expect(spec('প্রতিদিন রাত ১২টায়')['time'])->toBe('00:00');
    expect(spec('daily দুপুর ২টা')['time'])->toBe('14:00');
    expect(spec('daily 17:30')['time'])->toBe('17:30');
    expect(spec('every Monday 8am'))->toBe(['kind' => 'weekly', 'weekday' => 1, 'time' => '08:00']);
    expect(spec('প্রতি সোমবার বিকাল ৫:৩০'))->toBe(['kind' => 'weekly', 'weekday' => 1, 'time' => '17:30']);
    expect(spec('প্রতি শুক্রবার')['weekday'])->toBe(5);
    expect(spec('every monday')['time'])->toBe('09:00');
    expect(spec('প্রতি মাসের ৫ তারিখ সকাল ১০টায়'))->toBe(['kind' => 'monthly', 'dom' => 5, 'time' => '10:00']);
    expect(spec('every month on the 15th at 6pm'))->toBe(['kind' => 'monthly', 'dom' => 15, 'time' => '18:00']);
    expect(spec('every 2 hours'))->toBe(['kind' => 'interval', 'interval' => 120, 'time' => '00:00']);
    expect(spec('প্রতি ৩০ মিনিট')['interval'])->toBe(30);
    expect(spec('hourly')['interval'])->toBe(60);
    expect(spec('কাল সকাল ৮টায়'))->toBe(['kind' => 'once', 'date' => 'tomorrow', 'time' => '08:00']);
    expect(spec('today 5pm'))->toBe(['kind' => 'once', 'date' => 'today', 'time' => '17:00']);
    expect(spec('on 2026-12-05 at 10:15'))->toBe(['kind' => 'once', 'date' => '2026-12-05', 'time' => '10:15']);
});

it('refuses what it cannot read instead of guessing', function () {
    expect(P::parse('hello there'))->toHaveKey('error');
    expect(P::parse('every 5 minutes')['error'])->toContain('15 minutes');
    expect(P::parse('every 30 days'))->toHaveKey('error');
    expect(P::parse('on Monday 9am')['error'])->toContain('every Monday');
    expect(P::parse('every day at 25:00'))->toHaveKey('error');
    expect(P::parse('monthly')['error'])->toContain('Which day');
    expect(P::parse('প্রতি মাসের ৪০ তারিখ')['error'])->toContain('1 to 31');
    expect(P::parse('on 2026-02-31')['error'])->toContain('does not exist');
    expect(P::parse(''))->toHaveKey('error');
});

it('finds the next run in the right time zone', function () {
    $daily = ['kind' => 'daily', 'time' => '09:00'];
    expect(at($daily, '2026-11-13 08:00'))->toBe('2026-11-13 09:00');
    expect(at($daily, '2026-11-13 09:00'))->toBe('2026-11-14 09:00');           // strictly after
    expect(at($daily, '2026-11-13 10:00'))->toBe('2026-11-14 09:00');
    $weekly = ['kind' => 'weekly', 'weekday' => 1, 'time' => '08:00'];          // 2026-11-13 is a Friday
    expect(at($weekly, '2026-11-13 12:00'))->toBe('2026-11-16 08:00');
    expect(at($weekly, '2026-11-16 07:00'))->toBe('2026-11-16 08:00');
    expect(at($weekly, '2026-11-16 08:00'))->toBe('2026-11-23 08:00');
    $monthly = ['kind' => 'monthly', 'dom' => 31, 'time' => '10:00'];
    expect(at($monthly, '2027-02-01 00:00'))->toBe('2027-02-28 10:00');          // short month: last day
    expect(at($monthly, '2027-02-28 11:00'))->toBe('2027-03-31 10:00');
    expect(at(['kind' => 'monthly', 'dom' => 5, 'time' => '10:00'], '2026-12-20 00:00'))->toBe('2027-01-05 10:00');
    expect(at(['kind' => 'interval', 'interval' => 90, 'time' => '00:00'], '2026-11-13 08:00'))->toBe('2026-11-13 09:30');
    expect(at(['kind' => 'once', 'date' => '2026-11-14', 'time' => '08:00'], '2026-11-13 08:00'))->toBe('2026-11-14 08:00');
    expect(NextRun::after(['kind' => 'once', 'date' => '2026-11-12', 'time' => '08:00'], 'Asia/Dhaka', utc('2026-11-13 08:00')))->toBeNull();
    expect(NextRun::after($daily, 'Asia/Dhaka', utc('2026-11-13 08:00'))->format('H:i'))->toBe('03:00');   // returned in UTC
});

it('turns today and tomorrow into a fixed date once', function () {
    $now = utc('2026-11-13 23:30');
    expect(NextRun::resolve(['kind' => 'once', 'date' => 'tomorrow', 'time' => '08:00'], 'Asia/Dhaka', $now)['date'])->toBe('2026-11-14');
    expect(NextRun::resolve(['kind' => 'once', 'date' => 'today', 'time' => '08:00'], 'Asia/Dhaka', $now)['date'])->toBe('2026-11-13');
    expect(NextRun::resolve(['kind' => 'daily', 'time' => '08:00'], 'Asia/Dhaka', $now)['kind'])->toBe('daily');
});

it('describes a schedule in words', function () {
    expect(P::describe(['kind' => 'weekly', 'weekday' => 1, 'time' => '08:00']))->toBe('Every Monday at 08:00');
    expect(P::describe(['kind' => 'interval', 'interval' => 120, 'time' => '00:00']))->toBe('Every 2 hour(s)');
});

it('moves a claimed job forward from now, and finishes one-time jobs', function () {
    $now = utc('2026-11-13 08:00');
    $daily = (object) ['schedule' => json_encode(['kind' => 'daily', 'time' => '09:00']), 'tz' => 'Asia/Dhaka'];
    expect(App\Automation\JobRunner::nextFor($daily, $now)->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('Y-m-d H:i'))->toBe('2026-11-13 09:00');
    // the server was down for 3 days: one catch-up run, and the next time is in the future (not three runs)
    $late = utc('2026-11-16 12:00');
    expect(App\Automation\JobRunner::nextFor($daily, $late)->setTimezone(new DateTimeZone('Asia/Dhaka'))->format('Y-m-d H:i'))->toBe('2026-11-17 09:00');
    $once = (object) ['schedule' => json_encode(['kind' => 'once', 'date' => '2026-11-13', 'time' => '07:00']), 'tz' => 'Asia/Dhaka'];
    expect(App\Automation\JobRunner::nextFor($once, $now))->toBeNull();
});
