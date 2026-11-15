<?php

declare(strict_types=1);

namespace App\Messaging;

/** Handles the Approve/Reject buttons sent to Telegram. The approvals engine arrives in a later phase and replaces the null implementation. */
interface ApprovalDecider
{
    /** @return string text shown to the user as a toast, e.g. "Approval was given" */
    public function decide(string $tenantId, string $userId, string $approvalId, bool $approve): string;
}
