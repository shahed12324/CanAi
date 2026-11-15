<?php

declare(strict_types=1);

namespace App\Chat;

use App\Ai\ProviderGateway;

final class GatewayChatEngine implements ChatEngine
{
    public function __construct(private readonly ProviderGateway $gateway) {}

    public function stream(string $providerId, string $modelKey, array $messages, string $tenantId, ?string $userId, int $maxTokens): \Generator
    {
        yield from $this->gateway->stream($providerId, $modelKey, $messages, $tenantId, $userId, $maxTokens);
    }
}
