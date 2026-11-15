<?php

declare(strict_types=1);

namespace App\Voice;

interface VoiceEngine
{
    /**
     * @return array<string, mixed> status ok|empty|unsupported, text, reason
     *
     * @throws \App\Ai\ProviderCallException
     */
    public function transcribe(string $providerId, string $modelKey, string $mime, string $bytes, ?string $language, string $tenantId, string $userId): array;

    /**
     * @return array<string, mixed> status ok|unsupported, mime, audio_b64, chars, reason
     *
     * @throws \App\Ai\ProviderCallException
     */
    public function speak(string $providerId, string $modelKey, string $voice, string $text, string $tenantId, string $userId): array;
}
