<?php

declare(strict_types=1);

namespace App\Memory;

use App\Tenancy\TenantContext;

final class RepoMemoryContext implements MemoryContext
{
    public function __construct(private readonly MemoryRepo $repo, private readonly TenantContext $context) {}

    public function block(string $tenantId, string $userId): array
    {
        return $this->context->run($tenantId, fn () => MemoryPrompt::block($this->repo->forChat($userId, 60)));
    }
}
