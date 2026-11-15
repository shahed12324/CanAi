<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Calendar\CalendarService;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;

final class CalendarUpcomingTool implements Tool
{
    public function __construct(private readonly CalendarService $calendar) {}

    public function key(): string
    {
        return 'calendar.upcoming';
    }

    public function label(): string
    {
        return 'Upcoming events (from calendar, read-only)';
    }

    public function risk(): string
    {
        return Risk::READ;
    }

    public function args(): array
    {
        return ['days?' => 'How many days (1–30, default 2)'];
    }

    public function run(ToolContext $c, array $args): string
    {
        $days = ctype_digit(trim($args['days'] ?? '')) ? (int) trim($args['days']) : 2;
        $r = $this->calendar->upcoming($c->tenantId, $c->userId, $c->timezone, new \DateTimeImmutable('now'), $days);
        if ($r['feeds'] === 0) {
            return '(No calendar has been added)';
        }

        return CalendarService::lines($r['events']).($r['errors'] !== [] ? "\n(Could not be read: ".implode('; ', $r['errors']).')' : '');
    }
}
