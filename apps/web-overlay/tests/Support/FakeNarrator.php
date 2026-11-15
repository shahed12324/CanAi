<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Video\Narrator;

final class FakeNarrator implements Narrator
{
    /** @var list<array<string, string>> */
    public array $calls = [];

    public function __construct(public ?array $answer = null) {}

    public function speak(string $tenantId, string $userId, string $text, string $voice, string $requestId): array
    {
        $this->calls[] = compact('tenantId', 'userId', 'text', 'voice', 'requestId');

        return $this->answer ?? ['status' => 'ok', 'audio_b64' => base64_encode('ID3-VOICE'), 'credits' => '0.0300'];
    }
}
