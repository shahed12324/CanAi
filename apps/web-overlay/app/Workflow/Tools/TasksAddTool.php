<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Actions\Tenancy\CreateWorkspace;
use App\Productivity\ProductivityText;
use App\Productivity\TaskInput;
use App\Productivity\TaskRepo;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;

final class TasksAddTool implements Tool
{
    public function __construct(private readonly TaskRepo $tasks) {}

    public function key(): string
    {
        return 'tasks.add';
    }

    public function label(): string
    {
        return 'Add a task';
    }

    public function risk(): string
    {
        return Risk::WRITE_OWN;
    }

    public function args(): array
    {
        return ['title' => 'Task name', 'due?' => 'Due date (YYYY-MM-DD)', 'details?' => 'Description'];
    }

    public function run(ToolContext $c, array $args): string
    {
        $in = TaskInput::parse(['title' => $args['title'] ?? '', 'details' => $args['details'] ?? '', 'due_on' => $args['due'] ?? ''], $c->timezone);
        if ($in['error'] !== null) {
            throw new ToolException(ProductivityText::error($in['error']) ?? 'The task could not be added.');
        }
        $r = $this->tasks->add($c->tenantId, $c->userId, CreateWorkspace::newId(), $in['title'], $in['details'], 2, $in['due'], null);
        if ($r !== 'ok') {
            throw new ToolException(ProductivityText::error($r) ?? 'The task could not be added.');
        }

        return 'The task was added: '.$in['title'];
    }
}
