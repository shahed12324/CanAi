<?php

declare(strict_types=1);

namespace App\Studio;

interface ImagePicker
{
    /** @return array{id: string, provider_id: string, model_key: string, name: string, credit_multiplier: mixed}|null a model on the tenant's plan that can draw pictures (capability "image_gen") */
    public function pick(string $tenantId): ?array;
}
