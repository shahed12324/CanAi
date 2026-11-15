<?php

declare(strict_types=1);

namespace App\Messaging;

use App\Tenancy\TenantContext;
use App\Voice\VoiceActions;
use App\Voice\VoiceCommand;
use App\Voice\VoiceService;

/** Voice note -> VoiceService::listen (billed in credits, inside the tenant) -> optional workflow command. */
final class ServiceVoiceInbound implements VoiceInbound
{
    public function __construct(private readonly VoiceService $voice, private readonly VoiceActions $actions, private readonly TenantContext $tenant) {}

    public function handle(string $tenantId, string $userId, string $audio, string $requestId): array
    {
        return $this->tenant->run($tenantId, function () use ($tenantId, $userId, $audio, $requestId): array {
            $l = $this->voice->listen($tenantId, $userId, $audio, null, $requestId);
            if ($l['status'] !== 'ok' || $l['text'] === null) {
                return ['status' => $l['status'], 'transcript' => null, 'reply' => null];
            }
            $cmd = VoiceCommand::run($l['text'], $tenantId, $userId, $this->actions);

            return ['status' => 'ok', 'transcript' => $l['text'], 'reply' => $cmd['text'] ?? null];
        });
    }
}
