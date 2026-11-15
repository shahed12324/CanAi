<?php

declare(strict_types=1);

namespace App\Memory;

/** What the chat adds to its system prompt from the person's saved memory. Must never be allowed to break a chat. */
interface MemoryContext
{
    /** @return array{text: ?string, count: int} */
    public function block(string $tenantId, string $userId): array;
}
