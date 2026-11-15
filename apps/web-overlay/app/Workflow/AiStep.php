<?php

declare(strict_types=1);

namespace App\Workflow;

interface AiStep
{
    /**
     * @param  string  $key  unique per run step, used for the credit idempotency keys
     * @param  float  $budgetLeft  credits this run may still spend
     * @param  ?string  $modelId  the model the workflow prefers (the picker still checks the plan allows it; null = automatic)
     * @return array{status: string, text: string, credits: string} status: ok|budget|no_model|no_credits|provider|unreadable|failed
     */
    public function run(string $tenantId, string $userId, string $key, string $prompt, int $maxTokens, float $budgetLeft, ?string $modelId = null): array;
}
