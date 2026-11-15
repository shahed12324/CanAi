<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Links\ChatHooks;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;

/** Posts a message in one of the person's OWN saved Slack / Discord channels.  It leaves the app, so a workflow may only use it after an approval step. */
final class ChatPostTool implements Tool
{
    public function __construct(private readonly ChatHooks $hooks) {}

    public function key(): string
    {
        return 'chat.post';
    }

    public function label(): string
    {
        return 'Slack/Discord-Send a message to';
    }

    public function risk(): string
    {
        return Risk::EXTERNAL;
    }

    public function args(): array
    {
        return ['to' => 'Name of the saved connection', 'text' => 'Message'];
    }

    public function run(ToolContext $c, array $args): string
    {
        $r = $this->hooks->sendByName($c->tenantId, $c->userId, trim($args['to'] ?? ''), $args['text'] ?? '');
        if ($r !== 'ok') {
            throw new ToolException(ChatHooks::message($r));
        }

        return 'The message was sent: '.trim($args['to']);
    }
}
