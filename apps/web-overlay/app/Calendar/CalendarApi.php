<?php

declare(strict_types=1);

namespace App\Calendar;

use DateTimeImmutable;

interface CalendarApi
{
    /**
     * Events that overlap [from, to) in the person's primary calendar, cancelled ones left out, at most 100.
     *
     * @return list<array{start: DateTimeImmutable, end: DateTimeImmutable, all_day: bool, summary: string, location: string}>
     *
     * @throws CalendarApiException
     */
    public function events(string $accessToken, DateTimeImmutable $from, DateTimeImmutable $to, \DateTimeZone $zone): array;

    /**
     * Adds one event (no guests, no reminders beyond the calendar's default) to the person's primary calendar.
     *
     * @return string the provider's id of the new event
     *
     * @throws CalendarApiException
     */
    public function create(string $accessToken, string $title, DateTimeImmutable $start, DateTimeImmutable $end, string $location): string;
}
