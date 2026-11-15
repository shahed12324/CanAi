<?php

declare(strict_types=1);

namespace App\Social;

/** A month laid out as weeks (Saturday first, as in Bangladesh), each day holding the posts planned for it.  Pure. */
final class SocialCalendar
{
    /** @return array{0: int, 1: int} year, month from "YYYY-MM"; anything else gives the given fallback month */
    public static function month(?string $m, \DateTimeImmutable $fallback): array
    {
        if ($m !== null && preg_match('/\A(\d{4})-(0[1-9]|1[0-2])\z/', $m, $x) === 1 && (int) $x[1] >= 2000 && (int) $x[1] <= 2100) {
            return [(int) $x[1], (int) $x[2]];
        }

        return [(int) $fallback->format('Y'), (int) $fallback->format('n')];
    }

    /**
     * @param  list<array{id: string, scheduled_at: ?string}>  $posts  scheduled_at is any date-time string; it is read in $tz
     * @return list<list<array{date: string, day: int, in_month: bool, today: bool, posts: list<array<string, mixed>>}>>
     */
    public static function grid(int $year, int $month, array $posts, \DateTimeZone $tz, \DateTimeImmutable $now): array
    {
        $first = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $tz);
        $offset = ((int) $first->format('w') + 1) % 7;          // Saturday = 0
        $start = $first->modify("-{$offset} days");
        $days = (int) $first->format('t');
        $weeks = (int) ceil(($offset + $days) / 7);
        $byDay = [];
        foreach ($posts as $p) {
            if (($p['scheduled_at'] ?? null) === null) {
                continue;
            }
            try {
                $d = (new \DateTimeImmutable((string) $p['scheduled_at']))->setTimezone($tz)->format('Y-m-d');
            } catch (\Throwable) {
                continue;
            }
            $byDay[$d][] = $p;
        }
        $today = $now->setTimezone($tz)->format('Y-m-d');
        $grid = [];
        for ($w = 0; $w < $weeks; $w++) {
            $row = [];
            for ($i = 0; $i < 7; $i++) {
                $d = $start->modify('+'.($w * 7 + $i).' days');
                $key = $d->format('Y-m-d');
                $list = $byDay[$key] ?? [];
                usort($list, fn ($a, $b) => strcmp((string) $a['scheduled_at'], (string) $b['scheduled_at']));
                $row[] = ['date' => $key, 'day' => (int) $d->format('j'), 'in_month' => (int) $d->format('n') === $month, 'today' => $key === $today, 'posts' => $list];
            }
            $grid[] = $row;
        }

        return $grid;
    }

    /** "YYYY-MM" of the month before / after. */
    public static function neighbour(int $year, int $month, int $by): string
    {
        return (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->modify(($by >= 0 ? '+' : '').$by.' month')->format('Y-m');
    }
}
