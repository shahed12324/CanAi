<?php

declare(strict_types=1);

namespace App\Automation;

use DateTimeImmutable;
use DateTimeZone;

/** When does a schedule fire next? Always strictly after `$from`; returns UTC, or null when it never will (a "once" job in the past). */
final class NextRun
{
    /** Turns "today"/"tomorrow" into a real date once, at creation, so later calculations are stable. @param array<string, mixed> $spec @return array<string, mixed> */
    public static function resolve(array $spec, string $tz, DateTimeImmutable $now): array
    {
        if (($spec['kind'] ?? '') === 'once' && in_array($spec['date'], ['today', 'tomorrow'], true)) {
            $day = $now->setTimezone(new DateTimeZone($tz))->setTime(0, 0);
            $spec['date'] = ($spec['date'] === 'tomorrow' ? $day->modify('+1 day') : $day)->format('Y-m-d');
        }

        return $spec;
    }

    /** @param array<string, mixed> $s */
    public static function after(array $s, string $tz, DateTimeImmutable $from): ?DateTimeImmutable
    {
        $zone = new DateTimeZone($tz);
        $local = $from->setTimezone($zone);
        [$h, $m] = array_map('intval', explode(':', (string) ($s['time'] ?? '09:00')));
        $at = fn (DateTimeImmutable $d) => $d->setTime($h, $m);
        $utc = fn (?DateTimeImmutable $d) => $d?->setTimezone(new DateTimeZone('UTC'));

        switch ($s['kind']) {
            case 'interval':
                return $utc($local->modify('+'.(int) $s['interval'].' minutes'));
            case 'once':
                $when = new DateTimeImmutable($s['date'].' '.sprintf('%02d:%02d', $h, $m), $zone);

                return $when > $local ? $utc($when) : null;
            case 'daily':
                $c = $at($local);

                return $utc($c > $local ? $c : $at($local->modify('+1 day')));
            case 'weekly':
                for ($i = 0; $i <= 7; $i++) {
                    $d = $local->modify("+$i day");
                    if ((int) $d->format('N') === (int) $s['weekday'] && $at($d) > $local) {
                        return $utc($at($d));
                    }
                }

                return null;
            case 'monthly':
                for ($i = 0; $i <= 2; $i++) {
                    $first = $local->modify('first day of this month')->modify("+$i month");
                    $day = min((int) $s['dom'], (int) $first->format('t'));
                    $c = $at($first->setDate((int) $first->format('Y'), (int) $first->format('n'), $day));
                    if ($c > $local) {
                        return $utc($c);
                    }
                }

                return null;
        }

        return null;
    }
}
