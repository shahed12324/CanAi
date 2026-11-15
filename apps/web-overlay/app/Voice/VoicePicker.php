<?php

declare(strict_types=1);

namespace App\Voice;

interface VoicePicker
{
    /** @return array{id: string, provider_id: string, model_key: string, name: string, credit_multiplier: mixed}|null a model on the tenant's plan that can listen (audio_in) */
    public function pickListen(string $tenantId): ?array;

    /** @return array{id: string, provider_id: string, model_key: string, name: string, credit_multiplier: mixed}|null a model on the tenant's plan that can speak (audio_out) */
    public function pickSpeak(string $tenantId): ?array;
}
