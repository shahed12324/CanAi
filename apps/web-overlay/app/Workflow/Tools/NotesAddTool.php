<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Actions\Tenancy\CreateWorkspace;
use App\Productivity\ProductivityText;
use App\Productivity\TaskRepo;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;

final class NotesAddTool implements Tool
{
    public function __construct(private readonly TaskRepo $tasks) {}

    public function key(): string
    {
        return 'notes.add';
    }

    public function label(): string
    {
        return 'Add a note';
    }

    public function risk(): string
    {
        return Risk::WRITE_OWN;
    }

    public function args(): array
    {
        return ['title?' => 'Note title', 'body' => 'Note text'];
    }

    public function run(ToolContext $c, array $args): string
    {
        $title = mb_substr(trim($args['title'] ?? ''), 0, 200);
        $body = mb_substr(trim($args['body'] ?? ''), 0, 20000);
        $r = $this->tasks->saveNote($c->tenantId, $c->userId, CreateWorkspace::newId(), $title, $body, false, true);
        if ($r !== 'ok') {
            throw new ToolException(ProductivityText::error($r) ?? 'The note could not be added.');
        }

        return 'The note was added'.($title !== '' ? ': '.$title : '');
    }
}
