<?php

declare(strict_types=1);

namespace App\Chat;

use App\Ai\ModelCatalog;
use App\Tenancy\TenantContext;

/** Picks the model for a chat turn through the same catalog + router the model page uses (plan access, routing rules). */
final class CatalogModelPicker implements ModelPicker
{
    public function __construct(private readonly ModelCatalog $catalog, private readonly TenantContext $context) {}

    public function pick(string $tenantId, ?string $preferredModelId): ?array
    {
        return $this->context->run($tenantId, function () use ($tenantId, $preferredModelId): ?array {
            $candidates = $this->catalog->candidates($tenantId);
            $chosen = null;
            if ($preferredModelId !== null) {
                foreach ($candidates as $c) {
                    if ($c['id'] === $preferredModelId && in_array('text', $c['capabilities'], true)) {
                        $chosen = $c;
                    }
                }
            }
            $chosen ??= $this->catalog->choose($tenantId, 'chat', ['text']);
            if ($chosen === null) {
                return null;
            }

            return [
                'id' => $chosen['id'], 'provider_id' => $chosen['provider_id'], 'model_key' => $chosen['model_key'], 'name' => $chosen['name'],
                'max_output' => $chosen['max_output'], 'context_window' => $chosen['context_window'], 'credit_multiplier' => $chosen['credit_multiplier'],
            ];
        });
    }
}
