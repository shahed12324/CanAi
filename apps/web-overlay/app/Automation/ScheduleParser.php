<?php

declare(strict_types=1);

namespace App\Automation;

/**
 * "প্রতিদিন সকাল ৯টায়", "every Monday 8am", "প্রতি মাসের ৫ তারিখ", "every 2 hours", "কাল বিকাল ৪টায়" → a schedule spec.
 * Pure and deterministic (no model): what it cannot read it refuses, so a job never runs at a time the person did not mean.
 *
 * spec: kind daily|weekly|monthly|interval|once, time "HH:MM", weekday 1-7 (Mon=1), dom 1-31, interval minutes (15..10080), date "today"|"tomorrow"|"Y-m-d".
 */
final class ScheduleParser
{
    public const MIN_INTERVAL = 15;

    public const MAX_INTERVAL = 10080;

    private const WEEKDAYS = [
        1 => ['সোমবার', 'সোম', 'monday', 'mon'], 2 => ['মঙ্গলবার', 'মঙ্গল', 'tuesday', 'tue'], 3 => ['বুধবার', 'বুধ', 'wednesday', 'wed'],
        4 => ['বৃহস্পতিবার', 'বৃহস্পতি', 'thursday', 'thu'], 5 => ['শুক্রবার', 'শুক্র', 'friday', 'fri'], 6 => ['শনিবার', 'শনি', 'saturday', 'sat'], 7 => ['রবিবার', 'রবি', 'sunday', 'sun'],
    ];

    public const EXAMPLES = 'প্রতিদিন সকাল ৯টায় · every Monday 8am · প্রতি মাসের ৫ তারিখ সকাল ১০টায় · every 2 hours · কাল বিকাল ৪টায়';

    /** @return array{spec?: array<string, mixed>, error?: string} */
    public static function parse(string $text): array
    {
        $t = ' '.self::normalize($text).' ';
        $err = fn (string $m) => ['error' => $m];

        // every N minutes / hours
        if (preg_match('/(?:every|প্রতি)\s*(\d+)\s*(minutes?|mins?|hours?|hrs?|মিনিট|ঘণ্টা|ঘন্টা)/u', $t, $m)) {
            $min = (int) $m[1] * (preg_match('/^(h|ঘ)/u', $m[2]) ? 60 : 1);

            return self::interval($min);
        }
        if (preg_match('/(?:every hour|hourly|প্রতি ঘণ্টা|প্রতি ঘন্টা|প্রতিঘণ্টা)/u', $t)) {
            return self::interval(60);
        }

        $time = null;
        $spec = null;

        if (preg_match('/(?:প্রতি\s*মাসের?|প্রতি\s*মাসে|every month on(?: the)?|monthly on(?: the)?|monthly)\s*(\d{1,2})/u', $t, $m) || preg_match('/(\d{1,2})\s*(?:st|nd|rd|th)?\s*(?:of every month|of each month|তারিখে প্রতি মাস)/u', $t, $m)) {
            if ((int) $m[1] < 1 || (int) $m[1] > 31) {
                return $err('The day of the month must be 1 to 31.');
            }
            $spec = ['kind' => 'monthly', 'dom' => (int) $m[1]];
            $time = self::time(preg_replace('/(?:প্রতি\s*মাসের?|প্রতি\s*মাসে|every month on(?: the)?|monthly on(?: the)?|monthly)\s*\d{1,2}\s*(?:st|nd|rd|th)?\s*(?:তারিখ(?:ে)?)?/u', ' ', $t) ?? $t);
        } elseif (preg_match('/(?:প্রতি\s*মাস|every month|monthly)/u', $t)) {
            return $err('Which day of the month? Say, for example, “প্রতি মাসের ৫ তারিখ”.');
        } elseif (self::has($t, ['প্রতিদিন', 'প্রতি দিন', 'রোজ', 'every day', 'everyday', 'daily', 'each day', 'প্রত্যেক দিন'])) {
            $spec = ['kind' => 'daily'];
            $time = self::time($t);
        } elseif (preg_match('/(?:প্রতি|every|each|প্রত্যেক)\s*/u', $t) && ($wd = self::weekday($t)) !== null) {
            $spec = ['kind' => 'weekly', 'weekday' => $wd];
            $time = self::time($t);
        } elseif (self::has($t, ['প্রতি সপ্তাহ', 'weekly', 'every week', 'each week'])) {
            $spec = ['kind' => 'weekly', 'weekday' => self::weekday($t) ?? 1];
            $time = self::time($t);
        } elseif (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $t, $m)) {
            if (! checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4))) {
                return $err('That date does not exist.');
            }
            $spec = ['kind' => 'once', 'date' => $m[1]];
            $time = self::time(str_replace($m[1], ' ', $t));
        } elseif (self::has($t, ['আগামীকাল', 'আগামি কাল', 'কাল', 'tomorrow'])) {
            $spec = ['kind' => 'once', 'date' => 'tomorrow'];
            $time = self::time($t);
        } elseif (self::has($t, ['আজ', 'today'])) {
            $spec = ['kind' => 'once', 'date' => 'today'];
            $time = self::time($t);
        } elseif (self::weekday($t) !== null) {
            return $err('Say “every Monday” (প্রতি সোমবার) for a repeating job, or give a date.');
        }
        if ($spec === null) {
            return $err('I could not find when to run it. Try: '.self::EXAMPLES);
        }
        if ($time === false) {
            return $err('That time is not valid (for example “সকাল ৯টায়”, “9pm”, “17:30”).');
        }

        return ['spec' => $spec + ['time' => $time ?? '09:00']];
    }

    /** @return array{spec?: array<string, mixed>, error?: string} */
    private static function interval(int $min): array
    {
        if ($min < self::MIN_INTERVAL || $min > self::MAX_INTERVAL) {
            return ['error' => 'Repeat every '.self::MIN_INTERVAL.' minutes to 7 days (a job calls the AI each time, so very frequent runs cost credits).'];
        }

        return ['spec' => ['kind' => 'interval', 'interval' => $min, 'time' => '00:00']];
    }

    /** Human description, e.g. "Every Monday at 08:00". @param array<string, mixed> $s */
    public static function describe(array $s): string
    {
        $names = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

        return match ($s['kind']) {
            'daily' => 'Every day at '.$s['time'],
            'weekly' => 'Every '.$names[(int) $s['weekday']].' at '.$s['time'],
            'monthly' => 'On day '.$s['dom'].' of every month at '.$s['time'],
            'interval' => $s['interval'] % 60 === 0 ? 'Every '.($s['interval'] / 60).' hour(s)' : 'Every '.$s['interval'].' minutes',
            default => 'Once on '.$s['date'].' at '.$s['time'],
        };
    }

    private static function normalize(string $s): string
    {
        $s = strtr(mb_strtolower(trim($s)), ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);

        return (string) preg_replace('/\s+/u', ' ', $s);
    }

    /** @param list<string> $words */
    private static function has(string $t, array $words): bool
    {
        foreach ($words as $w) {
            if (str_contains($t, $w)) {
                return true;
            }
        }

        return false;
    }

    private static function weekday(string $t): ?int
    {
        foreach (self::WEEKDAYS as $n => $names) {
            foreach ($names as $w) {
                if (preg_match('/[a-z]/', $w) ? preg_match('/\b'.$w.'s?\b/', $t) === 1 : str_contains($t, $w)) {
                    return $n;
                }
            }
        }

        return null;
    }

    /** "HH:MM", null when no time was given, false when invalid. */
    private static function time(string $t): string|false|null
    {
        $period = null;
        if (self::has($t, ['সকাল', 'ভোর', ' am'])) {
            $period = 'am';
        } elseif (self::has($t, ['দুপুর'])) {
            $period = 'noon';
        } elseif (self::has($t, ['বিকাল', 'বিকেল', 'সন্ধ্যা', ' pm'])) {
            $period = 'pm';
        } elseif (self::has($t, ['রাত', 'night'])) {
            $period = 'night';
        }
        if (preg_match('/\b(\d{1,2}):(\d{2})\s*(am|pm)?/', $t, $m)) {
            [$h, $min] = [(int) $m[1], (int) $m[2]];
            $period = isset($m[3]) && $m[3] !== '' ? $m[3] : $period;
        } elseif (preg_match('/\b(\d{1,2})\s*(am|pm)\b/', $t, $m)) {
            [$h, $min, $period] = [(int) $m[1], 0, $m[2]];
        } elseif (preg_match('/(?:\bat\s*|এ\s*|সকাল\s*|ভোর\s*|দুপুর\s*|বিকাল\s*|বিকেল\s*|সন্ধ্যা\s*|রাত\s*)(\d{1,2})\b/u', $t, $m) || preg_match('/\b(\d{1,2})\s*(?:টা|টায়|বাজে)/u', $t, $m)) {
            [$h, $min] = [(int) $m[1], 0];
        } else {
            return null;
        }
        if ($min > 59 || $h > 24 || ($h > 12 && in_array($period, ['am', 'pm', 'noon', 'night'], true) && $period !== 'night')) {
            return false;
        }
        if ($h <= 12) {
            $h = match ($period) {
                'am' => $h % 12,
                'pm' => $h % 12 + 12,
                'noon' => $h === 12 ? 12 : ($h <= 5 ? $h + 12 : $h),
                'night' => $h === 12 ? 0 : ($h >= 6 ? $h + 12 : $h),
                default => $h,
            };
        }

        return $h === 24 ? '00:00' : sprintf('%02d:%02d', $h, $min);
    }
}
