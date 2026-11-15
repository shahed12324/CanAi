<?php

declare(strict_types=1);

namespace App\Video;

use App\Ai\ProviderGateway;

final class GatewayVideoEngine implements VideoEngine
{
    public function __construct(private readonly ProviderGateway $gateway) {}

    public function render(array $payload, string $tenantId, string $userId): array
    {
        return $this->gateway->renderVideo($payload, $tenantId, $userId);
    }

    public function edit(array $payload, string $tenantId, string $userId): array
    {
        return $this->gateway->editVideo($payload, $tenantId, $userId);
    }

    public function probe(string $videoB64, string $tenantId, string $userId): array
    {
        return $this->gateway->probeVideo($videoB64, $tenantId, $userId);
    }
}
