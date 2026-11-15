<?php

declare(strict_types=1);

namespace App\Pc;

use App\Ai\ProviderGateway;

final class GatewayPlanEngine implements PlanEngine
{
    public function __construct(private readonly ProviderGateway $gateway) {}

    public function draft(string $providerId, string $modelKey, string $goal, array $roots, array $kinds, string $tenantId, string $userId, array $apps = [], array $hosts = []): array
    {
        return $this->gateway->planDraft($providerId, $modelKey, $goal, $roots, $kinds, $tenantId, $userId, $apps, $hosts);
    }
}
