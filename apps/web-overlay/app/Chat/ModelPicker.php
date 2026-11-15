<?php

declare(strict_types=1);

namespace App\Chat;

interface ModelPicker
{
    /**
     * The model to answer with: the user's pick if allowed for the tenant's plan, otherwise the router's choice.
     *
     * @return array{id: string, provider_id: string, model_key: string, name: string, max_output: ?int, context_window: ?int, credit_multiplier: ?float}|null
     */
    public function pick(string $tenantId, ?string $preferredModelId): ?array;
}
