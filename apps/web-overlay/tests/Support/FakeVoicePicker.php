<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Voice\VoicePicker;

final class FakeVoicePicker implements VoicePicker
{
    public function __construct(public ?array $listen = ['id' => 'L1', 'provider_id' => 'p1', 'model_key' => 'whisper-1', 'name' => 'W', 'credit_multiplier' => 1.0], public ?array $speak = ['id' => 'S1', 'provider_id' => 'p1', 'model_key' => 'tts-1', 'name' => 'T', 'credit_multiplier' => 1.0]) {}

    public function pickListen(string $tenantId): ?array
    {
        return $this->listen;
    }

    public function pickSpeak(string $tenantId): ?array
    {
        return $this->speak;
    }
}
