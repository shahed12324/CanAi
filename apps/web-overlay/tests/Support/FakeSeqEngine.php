<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Chat\ChatEngine;

/** One scripted answer per call (FakeEngine replays the same script every call). The last turn repeats if more calls come. */
final class FakeSeqEngine implements ChatEngine
{
    public int $calls = 0;

    /** @param list<list<array<string, mixed>|\Throwable>> $turns */
    public function __construct(public array $turns = []) {}

    public function stream(string $providerId, string $modelKey, array $messages, string $tenantId, ?string $userId, int $maxTokens): \Generator
    {
        $this->calls++;
        $turn = count($this->turns) > 1 ? array_shift($this->turns) : ($this->turns[0] ?? []);
        foreach ($turn as $step) {
            if ($step instanceof \Throwable) {
                throw $step;
            }
            yield $step + ['input_tokens' => null, 'output_tokens' => null, 'finish_reason' => null];
        }
    }
}
