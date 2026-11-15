<?php

declare(strict_types=1);

namespace App\Vision;

use App\Ai\ModelCatalog;
use App\BillScan\VisionPicker;
use App\Tenancy\TenantContext;

/** A model on the tenant's plan that can read images, chosen under the "vision_read" task (an admin routing rule may steer it). */
final class CatalogReadPicker implements VisionPicker
{
    public function __construct(private readonly ModelCatalog $catalog, private readonly TenantContext $context) {}

    public function pick(string $tenantId): ?array
    {
        return $this->context->run($tenantId, function () use ($tenantId): ?array {
            $c = $this->catalog->choose($tenantId, 'vision_read', ['vision']);

            return $c === null ? null : ['id' => $c['id'], 'provider_id' => $c['provider_id'], 'model_key' => $c['model_key'], 'name' => $c['name'], 'credit_multiplier' => $c['credit_multiplier']];
        });
    }
}
