<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Messaging\VoiceInbound;

final class FakeVoiceInbound implements VoiceInbound
{
    public array $calls = [];
    public array $result = ['status' => 'ok', 'transcript' => 'what is the weather', 'reply' => null];

    public function handle(string $tenantId, string $userId, string $audio, string $requestId): array
    {
        $this->calls[] = compact('tenantId', 'userId', 'requestId');

        return $this->result;
    }
}
