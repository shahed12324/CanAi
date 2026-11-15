<?php

declare(strict_types=1);

namespace App\Video;

use App\Voice\VoiceService;

final class VoiceNarrator implements Narrator
{
    public function __construct(private readonly VoiceService $voice) {}

    public function speak(string $tenantId, string $userId, string $text, string $voice, string $requestId): array
    {
        $r = $this->voice->speak($tenantId, $userId, $text, $voice, $requestId);

        return ['status' => $r['status'], 'audio_b64' => $r['audio_b64'], 'credits' => $r['credits']];
    }
}
