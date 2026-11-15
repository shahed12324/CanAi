<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Chat\ChatEngine;

final class FakeEngine implements ChatEngine
{
    /** @param list<array<string, mixed>|\Throwable> $script events to yield, or an exception to throw at that point */
    public function __construct(public array $script = []) {}

    public int $calls = 0;

    /** @var list<list<array{role: string, content: string}>> messages of each call */
    public array $seenMessages = [];

    public function stream(string $providerId, string $modelKey, array $messages, string $tenantId, ?string $userId, int $maxTokens): \Generator
    {
        $this->calls++;
        $this->seenMessages[] = $messages;
        foreach ($this->script as $step) {
            if ($step instanceof \Throwable) {
                throw $step;
            }
            yield $step + ['input_tokens' => null, 'output_tokens' => null, 'finish_reason' => null];
        }
    }
}
