<?php

declare(strict_types=1);

namespace App\Vision;

use App\Ai\ProviderGateway;

final class GatewayVisionEngine implements VisionEngine
{
    public function __construct(private readonly ProviderGateway $gateway) {}

    public function read(string $providerId, string $modelKey, string $mime, string $bytes, string $mode, string $tenantId, string $userId): array
    {
        return $this->gateway->readImage($providerId, $modelKey, $mime, $bytes, $mode, $tenantId, $userId);
    }
}
