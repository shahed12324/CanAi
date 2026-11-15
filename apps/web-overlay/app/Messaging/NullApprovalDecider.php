<?php

declare(strict_types=1);

namespace App\Messaging;

final class NullApprovalDecider implements ApprovalDecider
{
    public function decide(string $tenantId, string $userId, string $approvalId, bool $approve): string
    {
        return 'This request is no longer valid.';
    }
}
