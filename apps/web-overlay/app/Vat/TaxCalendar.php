<?php

declare(strict_types=1);

namespace App\Vat;

use DateTimeImmutable;

/**
 * Turns the person's obligations (monthly / quarterly / yearly / once, each with a day) into dated agenda rows.
 * It knows no NBR deadline: every day and month comes from the rows the person keeps. A day past the month's end (31 in April) falls on the last day.
 */
final class TaxCalendar
{
    /**
     * @param  array{freq: string, due_day: ?int, due_month: ?int, once_date: ?string}  $o
     * @return list<string> Y-m-d, ascending, from..to inclusive
     */
    public static function dueDates(array $o, string $from, string $to): array
    {
        if ($from > $to) {
            return [];
        }
        if ($o['freq'] === 'once') {
            $d = (string) ($o['once_date'] ?? '');

            return $d !== '' && $d >= $from && $d <= $to ? [$d] : [];
        }
        $day = (int) ($o['due_day'] ?? 0);
        $mon = (int) ($o['due_month'] ?? 0);
        if ($day < 1 || $day > 31) {
            return [];
        }
        $out = [];
        $cur = (new DateTimeImmutable($from))->modify('first day of this month');
        $end = new DateTimeImmutable($to);
        while ($cur <= $end) {
            $m = (int) $cur->format('n');
            $hit = match ($o['freq']) {
                'monthly' => true,
                'quarterly' => (($m - 1) % 3) + 1 === $mon,
                'yearly' => $m === $mon,
                default => false,
            };
            if ($hit) {
                $d = $cur->setDate((int) $cur->format('Y'), $m, min($day, (int) $cur->format('t')))->format('Y-m-d');
                if ($d >= $from && $d <= $to) {
                    $out[] = $d;
                }
            }
            $cur = $cur->modify('first day of next month');
        }

        return $out;
    }

    public static function daysBetween(string $a, string $b): int
    {
        return (int) (new DateTimeImmutable($a))->diff(new DateTimeImmutable($b))->format('%r%a');
    }

    /**
     * @param  list<array<string, mixed>>  $obligations
     * @param  array<string, string>  $done  "obligationId|due" => done_on
     * @return list<array{id: string, label: string, kind: string, due: string, days: int, status: string, done_on: ?string, remind_days: int}> overdue first, then by date
     */
    public static function agenda(array $obligations, array $done, string $today, int $lookbackDays = 90, int $aheadDays = 120): array
    {
        $from = (new DateTimeImmutable($today))->modify("-{$lookbackDays} days")->format('Y-m-d');
        $to = (new DateTimeImmutable($today))->modify("+{$aheadDays} days")->format('Y-m-d');
        $rows = [];
        foreach ($obligations as $o) {
            if (! ($o['active'] ?? true)) {
                continue;
            }
            foreach (self::dueDates($o, $from, $to) as $due) {
                $doneOn = $done[$o['id'].'|'.$due] ?? null;
                $days = self::daysBetween($today, $due);
                $remind = (int) ($o['remind_days'] ?? 7);
                $status = $doneOn !== null ? 'done' : ($days < 0 ? 'overdue' : ($days <= $remind ? 'due_soon' : 'upcoming'));
                $rows[] = ['id' => (string) $o['id'], 'label' => (string) $o['label'], 'kind' => (string) $o['kind'], 'due' => $due, 'days' => $days, 'status' => $status, 'done_on' => $doneOn, 'remind_days' => $remind];
            }
        }
        usort($rows, fn ($a, $b) => [$a['due'], $a['label']] <=> [$b['due'], $b['label']]);

        return $rows;
    }

    /** @param list<array{status: string}> $rows */
    public static function counts(array $rows): array
    {
        $c = ['overdue' => 0, 'due_soon' => 0, 'upcoming' => 0, 'done' => 0];
        foreach ($rows as $r) {
            $c[$r['status']]++;
        }

        return $c;
    }
}
