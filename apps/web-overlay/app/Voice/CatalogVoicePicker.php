<?php

declare(strict_types=1);

namespace App\Voice;

use App\Ai\ModelCatalog;
use App\Tenancy\TenantContext;

/** Models are chosen by capability: "audio_in" listens, "audio_out" speaks (admin marks them on the model; a routing rule may steer the choice). */
final class CatalogVoicePicker implements VoicePicker
{
    public function __construct(private readonly ModelCatalog $catalog, private readonly TenantContext $context) {}

    public function pickListen(string $tenantId): ?array
    {
        return $this->pick($tenantId, 'voice_stt', 'audio_in');
    }

    public function pickSpeak(string $tenantId): ?array
    {
        return $this->pick($tenantId, 'voice_tts', 'audio_out');
    }

    private function pick(string $tenantId, string $task, string $need): ?array
    {
        return $this->context->run($tenantId, function () use ($tenantId, $task, $need): ?array {
            $c = $this->catalog->choose($tenantId, $task, [$need]);

            return $c === null ? null : ['id' => $c['id'], 'provider_id' => $c['provider_id'], 'model_key' => $c['model_key'], 'name' => $c['name'], 'credit_multiplier' => $c['credit_multiplier']];
        });
    }
}
