<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Calendar\CalendarAccounts;
use App\Productivity\TaskInput;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;

/** Adds ONE event to the person's own connected Google / Outlook calendar.  No guests, never edits or deletes.  It changes something outside the app, so a workflow may use it only after an approval step. */
final class CalendarAddEventTool implements Tool
{
    public function __construct(private readonly CalendarAccounts $accounts) {}

    public function key(): string
    {
        return 'calendar.add_event';
    }

    public function label(): string
    {
        return 'Add an event to the calendar (Google/Outlook)';
    }

    public function risk(): string
    {
        return Risk::EXTERNAL;
    }

    public function args(): array
    {
        return ['title' => 'Event name', 'start' => 'Start: 2026-12-01 15:30 (in your time)', 'minutes?' => 'How many minutes (5–1440, default 60)', 'location?' => 'Place', 'calendar?' => 'Email of the connected account (the first if not given)'];
    }

    public function run(ToolContext $c, array $args): string
    {
        $min = trim($args['minutes'] ?? '');
        if ($min !== '' && ! ctype_digit($min)) {
            throw new ToolException(CalendarAccounts::message('bad_input'));
        }
        $link = null;
        $want = mb_strtolower(trim($args['calendar'] ?? ''));
        if ($want !== '') {
            foreach ($this->accounts->list($c->userId) as $l) {
                if (mb_strtolower($l['name']) === $want) {
                    $link = $l['id'];
                }
            }
            if ($link === null) {
                throw new ToolException(CalendarAccounts::message('not_found'));
            }
        }
        $r = $this->accounts->create($c->tenantId, $c->userId, $link, $args['title'] ?? '', $args['start'] ?? '', $min === '' ? 60 : (int) $min, $args['location'] ?? '', TaskInput::zone($c->timezone));
        if ($r['status'] !== 'ok') {
            throw new ToolException(CalendarAccounts::message($r['status']));
        }

        return 'Added to the calendar: '.trim($args['title']).' ('.trim($args['start']).')';
    }
}
