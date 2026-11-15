<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Memory\MemoryEngine;

final class FakeMemoryEngine implements MemoryEngine
{
    public int $calls = 0;

    /** @var list<list<array{role: string, content: string}>> */
    public array $seen = [];

    public function __construct(public mixed $answer = null) {}

    public function complete(string $providerId, string $modelKey, array $messages, string $tenantId, string $userId): array
    {
        $this->calls++;
        $this->seen[] = $messages;
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer ?? ['text' => '[]', 'input_tokens' => 800, 'output_tokens' => 60, 'model' => 'gpt-test'];
    }
}
