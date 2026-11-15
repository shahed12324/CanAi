<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Productivity\TaskRepo;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;

final class TasksListTool implements Tool
{
    public function __construct(private readonly TaskRepo $tasks) {}

    public function key(): string
    {
        return 'tasks.list';
    }

    public function label(): string
    {
        return 'List of ongoing tasks';
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
        $prio = [1 => 'Urgent', 2 => 'General', 3 => 'Later'];
        $lines = [];
        foreach ($this->tasks->tasks($c->userId, 0) as $t) {
            if ($t['status'] === 'open') {
                $lines[] = '- ['.$prio[$t['priority']].'] '.$t['title'].($t['due_on'] !== null ? ' (End '.$t['due_on'].')' : '');
            }
            if (count($lines) >= 40) {
                break;
            }
        }

        return $lines === [] ? '(No ongoing tasks)' : implode("\n", $lines);
    }
}
