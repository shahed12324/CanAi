<?php

declare(strict_types=1);

namespace App\Reports;

/** Report periods.  Always whole days ending YESTERDAY (today is still incomplete).  The ad data we keep is the last 30 days, so a week can be compared with the week before it, a month cannot. Pure. */
final class ReportPeriod
{
    public const KINDS = ['week' => 7, 'month' => 30];

    /** @return array{from: string, to: string, prev_from: ?string, prev_to: ?string, days: int}|null */
    public static function range(string $kind, \DateTimeInterface $today): ?array
    {
        $days = self::KINDS[$kind] ?? null;
        if ($days === null) {
            return null;
        }
        $t = \DateTimeImmutable::createFromInterface($today)->setTime(0, 0);
        $to = $t->modify('-1 day');
        $from = $to->modify('-'.($days - 1).' days');
        $hasPrev = $kind === 'week';

        return ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'prev_from' => $hasPrev ? $from->modify("-{$days} days")->format('Y-m-d') : null, 'prev_to' => $hasPrev ? $from->modify('-1 day')->format('Y-m-d') : null, 'days' => $days];
    }
}
