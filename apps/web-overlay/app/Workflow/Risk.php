<?php

declare(strict_types=1);

namespace App\Workflow;

/**
 * How dangerous a tool is.  read: only looks.  write_own: adds to the person's OWN data (a task, a note, a notification to themselves).
 * external: changes something outside the app (sends an email, posts, messages a customer) - must come after an approval step.
 * money: moves money - never allowed in a workflow.
 */
final class Risk
{
    public const READ = 'read';

    public const WRITE_OWN = 'write_own';

    public const EXTERNAL = 'external';

    public const MONEY = 'money';

    public const LABELS = [self::READ => 'Only looks', self::WRITE_OWN => 'Writes on your own data', self::EXTERNAL => 'Sends outside (needs approval)', self::MONEY => 'Moves money (forbidden)'];
}
