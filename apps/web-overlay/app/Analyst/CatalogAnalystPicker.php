<?php

declare(strict_types=1);

namespace App\Analyst;

use App\Ai\ModelCatalog;
use App\Tenancy\TenantContext;

final class CatalogAnalystPicker implements AnalystPicker
{
    public function __construct(private readonly ModelCatalog $catalog, private readonly TenantContext $context) {}

    public function pick(string $tenantId): ?array
    {
        return $this->context->run($tenantId, function () use ($tenantId): ?array {
            $c = $this->catalog->choose($tenantId, 'analyst', ['text']);

            return $c === null ? null : ['id' => $c['id'], 'provider_id' => $c['provider_id'], 'model_key' => $c['model_key'], 'name' => $c['name'], 'credit_multiplier' => $c['credit_multiplier']];
        });
    }
}
