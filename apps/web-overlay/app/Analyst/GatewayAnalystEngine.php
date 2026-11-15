<?php

declare(strict_types=1);

namespace App\Analyst;

use App\Ai\ProviderGateway;

final class GatewayAnalystEngine implements AnalystEngine
{
    public function __construct(private readonly ProviderGateway $gateway) {}

    public function explain(string $providerId, string $modelKey, array $messages, string $tenantId, string $userId): array
    {
        $r = $this->gateway->complete($providerId, $modelKey, $messages, $tenantId, $userId, 900, 0.2);

        return ['text' => (string) ($r['text'] ?? ''), 'input_tokens' => $r['input_tokens'] ?? null, 'output_tokens' => $r['output_tokens'] ?? null, 'model' => (string) ($r['model'] ?? $modelKey)];
    }
}
