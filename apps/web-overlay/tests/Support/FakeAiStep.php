<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Workflow\AiStep;

final class FakeAiStep implements AiStep
{
    /** @var list<array{key: string, prompt: string, max: int, budget: float, model: ?string}> */
    public array $calls = [];

    public function __construct(public string $status = 'ok', public string $text = 'AI উত্তর', public string $credits = '0.5000') {}

    public function run(string $tenantId, string $userId, string $key, string $prompt, int $maxTokens, float $budgetLeft, ?string $modelId = null): array
    {
        $this->calls[] = ['key' => $key, 'prompt' => $prompt, 'max' => $maxTokens, 'budget' => $budgetLeft, 'model' => $modelId];

        return $this->status === 'ok' ? ['status' => 'ok', 'text' => $this->text, 'credits' => $this->credits] : ['status' => $this->status, 'text' => '', 'credits' => '0'];
    }
}
