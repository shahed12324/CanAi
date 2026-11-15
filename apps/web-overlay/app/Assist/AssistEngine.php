<?php

declare(strict_types=1);

namespace App\Assist;

interface AssistEngine
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{text: string, input_tokens: ?int, output_tokens: ?int, model: string}
     *
     * @throws \App\Ai\ProviderCallException
     */
    public function complete(string $providerId, string $modelKey, array $messages, string $tenantId, string $userId, int $maxTokens): array;
}
