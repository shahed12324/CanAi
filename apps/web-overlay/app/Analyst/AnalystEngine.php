<?php

declare(strict_types=1);

namespace App\Analyst;

interface AnalystEngine
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{text: string, input_tokens: ?int, output_tokens: ?int, model: string}
     *
     * @throws \App\Ai\ProviderCallException
     */
    public function explain(string $providerId, string $modelKey, array $messages, string $tenantId, string $userId): array;
}
