<?php

declare(strict_types=1);

namespace App\Studio;

use App\Ai\ModelCatalog;
use App\Tenancy\TenantContext;

/** Picture models are chosen by capability: the admin marks a model "image_gen" (a routing rule for task "image_gen" may steer the choice). */
final class CatalogImagePicker implements ImagePicker
{
    public function __construct(private readonly ModelCatalog $catalog, private readonly TenantContext $context) {}

    public function pick(string $tenantId): ?array
    {
        return $this->context->run($tenantId, function () use ($tenantId): ?array {
            $c = $this->catalog->choose($tenantId, 'image_gen', ['image_gen']);

            return $c === null ? null : ['id' => $c['id'], 'provider_id' => $c['provider_id'], 'model_key' => $c['model_key'], 'name' => $c['name'], 'credit_multiplier' => $c['credit_multiplier']];
        });
    }
}
