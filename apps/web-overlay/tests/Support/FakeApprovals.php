<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Messaging\ApprovalDecider;

final class FakeApprovals implements ApprovalDecider
{
    public array $calls = [];

    public function decide(string $tenantId, string $userId, string $approvalId, bool $approve): string
    {
        $this->calls[] = [$tenantId, $userId, $approvalId, $approve];

        return $approve ? 'Allowed' : 'Cancel';
    }
}
