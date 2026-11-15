<?php

declare(strict_types=1);

namespace App\Calendar;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Reads an iCalendar (.ics) feed - the "secret address in iCal format" that Google Calendar and the "published calendar" link of Outlook give out - and lists
 * the events that fall in a time window.  Pure and defensive: the text is untrusted, so sizes and loop counts are capped and bad lines are skipped.
 *
 * Understood: SUMMARY, LOCATION, DTSTART/DTEND (UTC "Z", TZID=, all-day DATE, floating), STATUS:CANCELLED, EXDATE, RECURRENCE-ID (a changed single occurrence),
 * RRULE with FREQ=DAILY|WEEKLY|MONTHLY|YEARLY, INTERVAL, COUNT, UNTIL and (weekly) BYDAY.  Other RRULE parts (BYSETPOS, BYYEARDAY ...) are ignored,
 * so such an event shows on its normal date pattern only.
 */
final class Ics
{
    public const MAX_BYTES = 2_000_000;

    public const MAX_EVENTS = 3000;

    public const MAX_STEPS = 1500;

    private const DAYS = ['MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6, 'SU' => 7];

    private const WINDOWS = ['Bangladesh Standard Time' => 'Asia/Dhaka', 'UTC' => 'UTC', 'India Standard Time' => 'Asia/Kolkata', 'Pakistan Standard Time' => 'Asia/Karachi',
        'Arabian Standard Time' => 'Asia/Dubai', 'GMT Standard Time' => 'Europe/London', 'Eastern Standard Time' => 'America/New_York', 'Pacific Standard Time' => 'America/Los_Angeles',
        'Central Standard Time' => 'America/Chicago', 'W. Europe Standard Time' => 'Europe/Berlin', 'Singapore Standard Time' => 'Asia/Singapore', 'Malay Peninsula Standard Time' => 'Asia/Kuala_Lumpur'];

    public static function looksLikeCalendar(string $text): bool
    {
        return str_contains(substr($text, 0, 2000), 'BEGIN:VCALENDAR');
    }

    /** @return list<array{uid: string, summary: string, location: string, start: DateTimeImmutable, end: DateTimeImmutable, all_day: bool, rrule: ?array<string, string>, exdates: list<int>, recurrence_id: ?int, cancelled: bool}> */
    public static function parse(string $ics, string $defaultTz = 'UTC'): array
    {
        $ics = substr($ics, 0, self::MAX_BYTES);
        $lines = preg_split('/\r\n|\n|\r/', (string) preg_replace('/(\r\n|\n|\r)[ \t]/', '', $ics)) ?: [];
        $calTz = null;
        foreach ($lines as $l) {
            if (str_starts_with($l, 'X-WR-TIMEZONE')) {
                $calTz = self::zone(substr($l, (int) strpos($l, ':') + 1), null);
            }
        }
        $fallback = $calTz ?? self::zone($defaultTz, null) ?? new DateTimeZone('UTC');
        $out = [];
        $cur = null;
        foreach ($lines as $line) {
            if ($line === 'BEGIN:VEVENT') {
                $cur = [];

                continue;
            }
            if ($line === 'END:VEVENT') {
                if ($cur !== null && ($e = self::event($cur, $fallback)) !== null) {
                    $out[] = $e;
                    if (count($out) >= self::MAX_EVENTS) {
                        break;
                    }
                }
                $cur = null;

                continue;
            }
            if ($cur === null || ! str_contains($line, ':')) {
                continue;
            }
            [$head, $val] = explode(':', $line, 2);
            $parts = explode(';', $head);
            $name = strtoupper(array_shift($parts));
            $params = [];
            foreach ($parts as $p) {
                if (str_contains($p, '=')) {
                    [$k, $v] = explode('=', $p, 2);
                    $params[strtoupper($k)] = trim($v, '"');
                }
            }
            $cur[$name][] = [$params, $val];
        }

        return $out;
    }

    /** @param array<string, list<array{0: array<string, string>, 1: string}>> $p */
    private static function event(array $p, DateTimeZone $fallback): ?array
    {
        if (! isset($p['DTSTART'][0])) {
            return null;
        }
        $s = self::time($p['DTSTART'][0][0], $p['DTSTART'][0][1], $fallback);
        if ($s === null) {
            return null;
        }
        [$start, $allDay] = $s;
        $end = isset($p['DTEND'][0]) ? (self::time($p['DTEND'][0][0], $p['DTEND'][0][1], $fallback)[0] ?? null) : null;
        if ($end === null && isset($p['DURATION'][0])) {
            try {
                $end = $start->add(new DateInterval(trim($p['DURATION'][0][1])));
            } catch (\Exception) {
                $end = null;
            }
        }
        $end ??= $allDay ? $start->modify('+1 day') : $start;
        if ($end < $start) {
            $end = $start;
        }
        $rrule = null;
        if (isset($p['RRULE'][0])) {
            $rrule = [];
            foreach (explode(';', $p['RRULE'][0][1]) as $kv) {
                if (str_contains($kv, '=')) {
                    [$k, $v] = explode('=', $kv, 2);
                    $rrule[strtoupper($k)] = strtoupper($v);
                }
            }
            if (! in_array($rrule['FREQ'] ?? '', ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'], true)) {
                $rrule = null;
            }
        }
        $ex = [];
        foreach ($p['EXDATE'] ?? [] as [$params, $val]) {
            foreach (explode(',', $val) as $v) {
                $t = self::time($params, $v, $fallback);
                if ($t !== null) {
                    $ex[] = $t[0]->getTimestamp();
                }
            }
        }
        $rid = isset($p['RECURRENCE-ID'][0]) ? (self::time($p['RECURRENCE-ID'][0][0], $p['RECURRENCE-ID'][0][1], $fallback)[0] ?? null) : null;

        return ['uid' => mb_substr((string) ($p['UID'][0][1] ?? ''), 0, 200), 'summary' => self::text($p['SUMMARY'][0][1] ?? '', 200), 'location' => self::text($p['LOCATION'][0][1] ?? '', 200),
            'start' => $start, 'end' => $end, 'all_day' => $allDay, 'rrule' => $rrule, 'exdates' => $ex, 'recurrence_id' => $rid?->getTimestamp(),
            'cancelled' => strtoupper(trim((string) ($p['STATUS'][0][1] ?? ''))) === 'CANCELLED'];
    }

    public static function text(string $v, int $max): string
    {
        $v = str_replace(['\\n', '\\N', '\\,', '\;', '\\\\'], [' ', ' ', ',', ';', '\\'], $v);
        $v = (string) preg_replace('/[\x00-\x1f\x7f]+/u', ' ', $v);

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $v)), 0, $max);
    }

    private static function zone(string $name, ?DateTimeZone $else): ?DateTimeZone
    {
        $name = trim($name, " \"\t");
        $name = self::WINDOWS[$name] ?? $name;
        if ($name !== '' && in_array($name, timezone_identifiers_list(), true)) {
            return new DateTimeZone($name);
        }

        return $else;
    }

    /** @param array<string, string> $params @return array{0: DateTimeImmutable, 1: bool}|null */
    private static function time(array $params, string $v, DateTimeZone $fallback): ?array
    {
        $v = trim($v);
        if (preg_match('/\A(\d{4})(\d{2})(\d{2})\z/', $v, $m) === 1) {
            if (! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return null;
            }

            return [new DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]} 00:00:00", $fallback), true];
        }
        if (preg_match('/\A(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})(Z?)\z/', $v, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1]) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 60) {
            return null;
        }
        $tz = $m[7] === 'Z' ? new DateTimeZone('UTC') : (isset($params['TZID']) ? (self::zone($params['TZID'], null) ?? $fallback) : $fallback);

        return [new DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:".min(59, (int) $m[6]), $tz), false];
    }

    /**
     * Events that overlap [from, to), repeats included, earliest first.
     *
     * @param  list<array<string, mixed>>  $events  from parse()
     * @return list<array{start: DateTimeImmutable, end: DateTimeImmutable, summary: string, location: string, all_day: bool}>
     */
    public static function between(array $events, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $changed = [];
        foreach ($events as $e) {
            if ($e['recurrence_id'] !== null) {
                $changed[$e['uid'].'|'.$e['recurrence_id']] = true;
            }
        }
        $out = [];
        foreach ($events as $e) {
            if ($e['cancelled']) {
                continue;
            }
            $len = $e['end']->getTimestamp() - $e['start']->getTimestamp();
            $add = function (DateTimeImmutable $s) use (&$out, $e, $len, $from, $to): void {
                $en = $s->modify("+{$len} seconds");
                if ($s < $to && ($en > $from || ($len === 0 && $s >= $from))) {
                    $out[] = ['start' => $s, 'end' => $en, 'summary' => $e['summary'], 'location' => $e['location'], 'all_day' => $e['all_day']];
                }
            };
            if ($e['rrule'] === null || $e['recurrence_id'] !== null) {
                $add($e['start']);

                continue;
            }
            foreach (self::occurrences($e, $from, $to) as $s) {
                $ts = $s->getTimestamp();
                if (in_array($ts, $e['exdates'], true) || isset($changed[$e['uid'].'|'.$ts])) {
                    continue;
                }
                $add($s);
            }
        }
        usort($out, fn ($a, $b) => [$a['start']->getTimestamp(), $a['summary']] <=> [$b['start']->getTimestamp(), $b['summary']]);

        return $out;
    }

    /** @param array<string, mixed> $e @return \Generator<DateTimeImmutable> */
    private static function occurrences(array $e, DateTimeImmutable $from, DateTimeImmutable $to): \Generator
    {
        $r = $e['rrule'];
        $interval = max(1, min(1000, (int) ($r['INTERVAL'] ?? 1)));
        $count = isset($r['COUNT']) ? max(1, (int) $r['COUNT']) : null;
        $until = null;
        if (isset($r['UNTIL'])) {
            $u = self::time([], $r['UNTIL'], $e['start']->getTimezone());
            $until = $u === null ? null : ($u[1] ? $u[0]->modify('+1 day') : $u[0]);
        }
        $start = $e['start'];
        $freq = $r['FREQ'];
        $days = [];
        if ($freq === 'WEEKLY' && isset($r['BYDAY'])) {
            foreach (explode(',', $r['BYDAY']) as $d) {
                $d = substr($d, -2);
                if (isset(self::DAYS[$d])) {
                    $days[] = self::DAYS[$d];
                }
            }
            sort($days);
        }
        if ($days === []) {
            $days = [(int) $start->format('N')];
        }
        $n = 0;
        $emitted = 0;
        // Without COUNT, skip straight to the window (an old daily event would otherwise use up the step cap long before today).
        $len = (int) ceil(($e['end']->getTimestamp() - $start->getTimestamp()) / 86400);       // a long event started days ago can still be running
        $gapDays = $from > $start ? max(0, intdiv($from->getTimestamp() - $start->getTimestamp(), 86400) - $len) : 0;
        $skip = static fn (int $units): int => $count !== null ? 0 : max(0, intdiv($units, $interval) - 1) * $interval;
        if ($freq === 'WEEKLY') {
            $weekStart = $start->modify('-'.((int) $start->format('N') - 1).' days');      // Monday of the first week
            for ($w = $skip(intdiv($gapDays, 7)); $n < self::MAX_STEPS; $w += $interval, $n++) {
                $monday = $weekStart->modify('+'.($w * 7).' days');
                foreach ($days as $d) {
                    $s = $monday->modify('+'.($d - 1).' days');
                    if ($s < $start) {
                        continue;
                    }
                    if ($s >= $to || ($until !== null && $s > $until) || ($count !== null && $emitted >= $count)) {
                        return;
                    }
                    $emitted++;

                    yield $s;
                }
            }

            return;
        }
        $unit = ['DAILY' => 'days', 'MONTHLY' => 'months', 'YEARLY' => 'years'][$freq];
        $i0 = $skip(match ($freq) {
            'DAILY' => $gapDays,
            'MONTHLY' => max(0, ((int) $from->format('Y') - (int) $start->format('Y')) * 12 + (int) $from->format('n') - (int) $start->format('n') - intdiv($len, 28) - 1),
            default => max(0, (int) $from->format('Y') - (int) $start->format('Y') - intdiv($len, 365) - 1),
        });
        for ($i = $i0; $n < self::MAX_STEPS; $i += $interval, $n++) {
            $s = $start->modify('+'.$i.' '.$unit);
            if ($unit === 'months' ? $s->format('j') !== $start->format('j') : ($unit === 'years' && $s->format('n-j') !== $start->format('n-j'))) {
                continue;                                                    // e.g. the 31st does not exist in this month: skip it (as calendars do)
            }
            if ($s >= $to || ($until !== null && $s > $until) || ($count !== null && $emitted >= $count)) {
                return;
            }
            $emitted++;

            yield $s;
        }
    }
}
