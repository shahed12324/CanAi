<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Productivity\DailyPlan;
use App\Productivity\TaskInput;
use App\Productivity\TaskRepo;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;

final class PlanTodayTool implements Tool
{
    public function __construct(private readonly TaskRepo $tasks) {}

    public function key(): string
    {
        return 'plan.today';
    }

    public function label(): string
    {
        return 'Today’s plan (from tasks)';
    }

    public function risk(): string
    {
        return Risk::READ;
    }

    public function args(): array
    {
        return [];
    }

    public function run(ToolContext $c, array $args): string
    {
        $open = array_values(array_filter($this->tasks->tasks($c->userId, 0), fn ($t) => $t['status'] === 'open'));
        $today = (new \DateTimeImmutable('now', TaskInput::zone($c->timezone)))->format('Y-m-d');
        $plan = DailyPlan::build($open, $today, $c->timezone);
        $groups = ['overdue' => 'Overdue', 'today' => 'Today’s', 'reminders' => 'Reminder today', 'soon' => 'In the next 3 days', 'anytime' => 'No date, but urgent'];
        $out = ["Date: {$today}"];
        foreach ($groups as $k => $label) {
            if ($plan[$k] !== []) {
                $out[] = $label.':';
                foreach ($plan[$k] as $t) {
                    $out[] = '- '.$t['title'].($t['due_on'] !== null ? ' (End '.$t['due_on'].')' : '');
                }
            }
        }

        return count($out) === 1 ? "Date: {$today}\n(Nothing urgent today)" : implode("\n", $out);
    }
}
