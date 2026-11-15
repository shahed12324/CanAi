<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Voice\VoiceEngine;

final class FakeVoiceEngine implements VoiceEngine
{
    /** @var list<array<string, mixed>> */
    public array $heard = [];

    /** @var list<array<string, mixed>> */
    public array $spoken = [];

    public function __construct(public mixed $listenAnswer = null, public mixed $speakAnswer = null) {}

    public function transcribe(string $providerId, string $modelKey, string $mime, string $bytes, ?string $language, string $tenantId, string $userId): array
    {
        $this->heard[] = compact('providerId', 'modelKey', 'mime', 'language', 'tenantId', 'userId') + ['size' => strlen($bytes)];
        if ($this->listenAnswer instanceof \Throwable) {
            throw $this->listenAnswer;
        }

        return $this->listenAnswer ?? ['status' => 'ok', 'text' => ' আজকের দাম কত? '];
    }

    public function speak(string $providerId, string $modelKey, string $voice, string $text, string $tenantId, string $userId): array
    {
        $this->spoken[] = compact('providerId', 'modelKey', 'voice', 'text');
        if ($this->speakAnswer instanceof \Throwable) {
            throw $this->speakAnswer;
        }

        return $this->speakAnswer ?? ['status' => 'ok', 'mime' => 'audio/mpeg', 'audio_b64' => 'QUJD', 'chars' => mb_strlen($text)];
    }
}
