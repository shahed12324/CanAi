<?php

declare(strict_types=1);

namespace App\Productivity;

use DateTimeImmutable;
use DateTimeZone;

/** Turns what a form sends into what the database wants. The reminder time is typed in the person's own time zone and stored as an exact moment. */
final class TaskInput
{
    /** @param array<string, mixed> $in @return array{error: ?string, title: string, details: string, priority: int, due: ?string, remind: ?string} */
    public static function parse(array $in, string $tz): array
    {
        $zone = self::zone($tz);
        $title = self::clean($in['title'] ?? '');
        $details = trim((string) ($in['details'] ?? ''));
        $out = ['error' => null, 'title' => $title, 'details' => $details, 'priority' => 2, 'due' => null, 'remind' => null];
        if ($title === '') {
            return ['error' => 'empty'] + $out;
        }
        if (mb_strlen($title) > 200 || mb_strlen($details) > 2000) {
            return ['error' => 'too_long'] + $out;
        }
        $p = (string) ($in['priority'] ?? '2');
        if (! in_array($p, ['1', '2', '3'], true)) {
            return ['error' => 'bad_priority'] + $out;
        }
        $out['priority'] = (int) $p;
        $due = trim((string) ($in['due_on'] ?? ''));
        if ($due !== '') {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', $due, $zone);
            if ($d === false || $d->format('Y-m-d') !== $due) {
                return ['error' => 'bad_date'] + $out;
            }
            $out['due'] = $due;
        }
        $rem = trim((string) ($in['remind_at'] ?? ''));
        if ($rem !== '') {
            $r = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $rem, $zone);
            if ($r === false || $r->format('Y-m-d\TH:i') !== $rem) {
                return ['error' => 'bad_time'] + $out;
            }
            $out['remind'] = $r->format('Y-m-d H:i:sP');
        }

        return $out;
    }

    public static function zone(string $tz): DateTimeZone
    {
        return new DateTimeZone(in_array($tz, timezone_identifiers_list(), true) ? $tz : 'Asia/Dhaka');
    }

    /** The value for an <input type="datetime-local"> in the person's zone. */
    public static function toLocalInput(?string $stored, string $tz): string
    {
        if ($stored === null || $stored === '') {
            return '';
        }

        return (new DateTimeImmutable($stored))->setTimezone(self::zone($tz))->format('Y-m-d\TH:i');
    }

    private static function clean(mixed $v): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $v));
    }
}
