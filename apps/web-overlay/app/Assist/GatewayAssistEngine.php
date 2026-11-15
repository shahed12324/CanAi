<?php

declare(strict_types=1);

namespace App\Assist;

use App\Ai\ProviderGateway;

final class GatewayAssistEngine implements AssistEngine
{
    public function __construct(private readonly ProviderGateway $gateway) {}

    public function complete(string $providerId, string $modelKey, array $messages, string $tenantId, string $userId, int $maxTokens): array
    {
        $r = $this->gateway->complete($providerId, $modelKey, $messages, $tenantId, $userId, $maxTokens, 0.3);

        return ['text' => (string) ($r['text'] ?? ''), 'input_tokens' => $r['input_tokens'] ?? null, 'output_tokens' => $r['output_tokens'] ?? null, 'model' => (string) ($r['model'] ?? $modelKey)];
    }
}
