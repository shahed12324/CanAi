<?php

declare(strict_types=1);

namespace App\Studio;

use App\Ai\ProviderGateway;

final class GatewayStudioEngine implements StudioEngine
{
    public function __construct(private readonly ProviderGateway $gateway) {}

    public function image(string $providerId, string $modelKey, string $prompt, string $size, string $tenantId, string $userId): array
    {
        return $this->gateway->image($providerId, $modelKey, $prompt, $size, $tenantId, $userId);
    }

    public function compose(array $payload, string $tenantId, string $userId): array
    {
        return $this->gateway->composeDesign($payload, $tenantId, $userId);
    }
}
