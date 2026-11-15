<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Memory\MemoryContext;

final class FakeMemoryContext implements MemoryContext
{
    public int $calls = 0;

    public function __construct(private readonly mixed $result = ['text' => 'NOTES-BLOCK', 'count' => 2]) {}

    public function block(string $tenantId, string $userId): array
    {
        $this->calls++;
        if ($this->result instanceof \Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}
