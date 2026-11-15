<?php

declare(strict_types=1);

namespace App\Analyst;

interface AnalystPicker
{
    /** @return array{id: string, provider_id: string, model_key: string, name: string, credit_multiplier: mixed}|null a text model on the tenant's plan */
    public function pick(string $tenantId): ?array;
}
