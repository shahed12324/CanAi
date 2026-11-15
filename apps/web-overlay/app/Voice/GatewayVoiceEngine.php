<?php

declare(strict_types=1);

namespace App\Voice;

use App\Ai\ProviderGateway;

final class GatewayVoiceEngine implements VoiceEngine
{
    public function __construct(private readonly ProviderGateway $gateway) {}

    public function transcribe(string $providerId, string $modelKey, string $mime, string $bytes, ?string $language, string $tenantId, string $userId): array
    {
        return $this->gateway->transcribe($providerId, $modelKey, $mime, $bytes, $language, $tenantId, $userId);
    }

    public function speak(string $providerId, string $modelKey, string $voice, string $text, string $tenantId, string $userId): array
    {
        return $this->gateway->speak($providerId, $modelKey, $voice, $text, $tenantId, $userId);
    }
}
