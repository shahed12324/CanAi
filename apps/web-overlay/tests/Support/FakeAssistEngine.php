<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Assist\AssistEngine;

final class FakeAssistEngine implements AssistEngine
{
    public int $calls = 0;

    /** @var list<array{messages: array, max: int}> */
    public array $seen = [];

    public function __construct(public mixed $answer = null) {}

    public function complete(string $providerId, string $modelKey, array $messages, string $tenantId, string $userId, int $maxTokens): array
    {
        $this->calls++;
        $this->seen[] = ['messages' => $messages, 'max' => $maxTokens];
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer ?? ['text' => '{"summary":"সারাংশ","decisions":["এক"],"actions":[{"task":"Job","owner":"করিম","due":"2026-11-10"}]}', 'input_tokens' => 900, 'output_tokens' => 200, 'model' => 'gpt-test'];
    }
}
