<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Calendar\CalendarApi;
use App\Calendar\CalendarApiException;
use DateTimeImmutable;

final class FakeCalendarApi implements CalendarApi
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public ?string $fail = null;          // auth | api | bad_reply

    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** @var list<string> access tokens seen */
    public array $tokens = [];

    public function events(string $accessToken, DateTimeImmutable $from, DateTimeImmutable $to, \DateTimeZone $zone): array
    {
        $this->tokens[] = $accessToken;
        if ($this->fail !== null) {
            throw new CalendarApiException($this->fail);
        }

        return $this->events;
    }

    public function create(string $accessToken, string $title, DateTimeImmutable $start, DateTimeImmutable $end, string $location): string
    {
        $this->tokens[] = $accessToken;
        if ($this->fail !== null) {
            throw new CalendarApiException($this->fail);
        }
        $this->created[] = compact('title', 'start', 'end', 'location');

        return 'evt'.count($this->created);
    }
}
