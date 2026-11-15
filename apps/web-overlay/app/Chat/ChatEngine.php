<?php

declare(strict_types=1);

namespace App\Chat;

use App\Ai\ProviderCallException;

interface ChatEngine
{
    /**
     * Streams a completion. Yields ['text' => string, 'input_tokens' => ?int, 'output_tokens' => ?int, 'finish_reason' => ?string].
     * Throws ProviderCallException (before the first yield for HTTP-level failures, or mid-stream).
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return \Generator<int, array{text: string, input_tokens: ?int, output_tokens: ?int, finish_reason: ?string}>
     *
     * @throws ProviderCallException
     */
    public function stream(string $providerId, string $modelKey, array $messages, string $tenantId, ?string $userId, int $maxTokens): \Generator;
}
