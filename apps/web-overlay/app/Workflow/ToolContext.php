<?php

declare(strict_types=1);

namespace App\Workflow;

final class ToolContext
{
    public function __construct(public readonly string $tenantId, public readonly string $userId, public readonly string $timezone = 'Asia/Dhaka') {}
}
