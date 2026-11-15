<?php

declare(strict_types=1);

namespace App\Video;

/** Reads a script aloud (the voice-over).  Charged as speech by whoever implements it. */
interface Narrator
{
    /** @return array{status: string, audio_b64: ?string, credits: string} status ok|no_model|no_credits|provider|unsupported|failed|too_long|... */
    public function speak(string $tenantId, string $userId, string $text, string $voice, string $requestId): array;
}
