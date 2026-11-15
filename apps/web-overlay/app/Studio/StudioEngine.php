<?php

declare(strict_types=1);

namespace App\Studio;

interface StudioEngine
{
    /**
     * @return array<string, mixed> status ok|unsupported, image_b64, reason
     *
     * @throws \App\Ai\ProviderCallException
     */
    public function image(string $providerId, string $modelKey, string $prompt, string $size, string $tenantId, string $userId): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> status ok|unsupported, items[], reason
     *
     * @throws \App\Ai\ProviderCallException
     */
    public function compose(array $payload, string $tenantId, string $userId): array;
}
