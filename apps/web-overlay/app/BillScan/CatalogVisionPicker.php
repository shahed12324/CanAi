<?php

declare(strict_types=1);

namespace App\BillScan;

use App\Ai\ModelCatalog;
use App\Tenancy\TenantContext;

final class CatalogVisionPicker implements VisionPicker
{
    public function __construct(private readonly ModelCatalog $catalog, private readonly TenantContext $context) {}

    public function pick(string $tenantId): ?array
    {
        return $this->context->run($tenantId, function () use ($tenantId): ?array {
            $c = $this->catalog->choose($tenantId, 'bill_scan', ['vision']);

            return $c === null ? null : ['id' => $c['id'], 'provider_id' => $c['provider_id'], 'model_key' => $c['model_key'], 'name' => $c['name'], 'credit_multiplier' => $c['credit_multiplier']];
        });
    }
}
