<?php

declare(strict_types=1);

namespace App\Memory;

use App\Ai\ProviderGateway;

final class GatewayMemoryEngine implements MemoryEngine
{
    public function __construct(private readonly ProviderGateway $gateway) {}

    public function complete(string $providerId, string $modelKey, array $messages, string $tenantId, string $userId): array
    {
        $r = $this->gateway->complete($providerId, $modelKey, $messages, $tenantId, $userId, 500, 0.1);

        return ['text' => (string) ($r['text'] ?? ''), 'input_tokens' => $r['input_tokens'] ?? null, 'output_tokens' => $r['output_tokens'] ?? null, 'model' => (string) ($r['model'] ?? $modelKey)];
    }
}
