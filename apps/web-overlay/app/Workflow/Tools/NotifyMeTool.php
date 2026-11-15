<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Mobile\MobileRepo;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;

/** A notification to the person who owns the workflow - in the app and, with the phone app, as a push. Never to anyone else. */
final class NotifyMeTool implements Tool
{
    public function __construct(private readonly MobileRepo $mobile) {}

    public function key(): string
    {
        return 'notify.me';
    }

    public function label(): string
    {
        return 'Send me a notification';
    }

    public function risk(): string
    {
        return Risk::WRITE_OWN;
    }

    public function args(): array
    {
        return ['title' => 'Title', 'body?' => 'Message'];
    }

    public function run(ToolContext $c, array $args): string
    {
        $title = mb_substr(trim($args['title'] ?? ''), 0, 160);
        if ($title === '') {
            throw new ToolException('The notification title is blank.');
        }
        $this->mobile->notify($c->tenantId, $c->userId, 'workflow', $title, mb_substr(trim($args['body'] ?? ''), 0, 500), 'workflow');

        return 'The notification was sent.';
    }
}
