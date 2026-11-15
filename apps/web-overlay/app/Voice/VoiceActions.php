<?php

declare(strict_types=1);

namespace App\Voice;

/** What a spoken command may do.  Deliberately tiny: list the person's own workflows and start one.  Nothing else can be reached by voice. */
interface VoiceActions
{
    /** @return list<array{id: string, name: string, enabled: bool, needs_input: bool}> */
    public function workflows(string $userId): array;

    /** @return string ok|busy|disabled|not_found|missing_input|failed */
    public function startWorkflow(string $tenantId, string $userId, string $workflowId): string;
}
