<?php

declare(strict_types=1);

namespace App\Workflow\Tools;

use App\Developer\WebhookRepo;
use App\Workflow\Risk;
use App\Workflow\Tool;
use App\Workflow\ToolContext;
use App\Workflow\ToolException;

/** Sends the workflow's text to one of the person's OWN webhook addresses (one that ticked the workflow.message event).  Acts outside the app: an approval step must come before it. */
final class WebhookSendTool implements Tool
{
    public const MESSAGES = [
        'not_found' => 'There is no active address with this name, or it has "Message sent by workflow" The event is not ticked (see the developer page).',
        'limit' => 'At most 20 messages per hour go to this address — try again shortly.',
        'bad_input' => 'The message is blank or over 1000 characters.',
    ];

    public function __construct(private readonly WebhookRepo $hooks) {}

    public function key(): string
    {
        return 'webhook.send';
    }

    public function label(): string
    {
        return 'Send a message to your own webhook address';
    }

    public function risk(): string
    {
        return Risk::EXTERNAL;
    }

    public function args(): array
    {
        return ['to' => 'Webhook Address name', 'text' => 'Message (max 1000 characters)'];
    }

    public function run(ToolContext $c, array $args): string
    {
        $r = $this->hooks->sendMessage($c->tenantId, $c->userId, trim($args['to'] ?? ''), (string) ($args['text'] ?? ''));
        if ($r !== 'ok') {
            throw new ToolException(self::MESSAGES[$r] ?? 'The message could not be sent.');
        }

        return 'The message was queued: '.trim($args['to']);
    }
}
