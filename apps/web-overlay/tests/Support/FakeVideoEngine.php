<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Video\VideoEngine;

final class FakeVideoEngine implements VideoEngine
{
    /** @var list<array<string, mixed>> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $edits = [];

    /** @var list<string> */
    public array $probes = [];

    public function __construct(public mixed $answer = null, public mixed $editAnswer = null, public mixed $probeAnswer = null) {}

    public function edit(array $payload, string $tenantId, string $userId): array
    {
        $this->edits[] = $payload;
        if ($this->editAnswer instanceof \Throwable) {
            throw $this->editAnswer;
        }

        return $this->editAnswer ?? ['status' => 'ok', 'bytes' => "\0\0\0\x18ftypmp42CLIP", 'reason' => null];
    }

    public function probe(string $videoB64, string $tenantId, string $userId): array
    {
        $this->probes[] = $videoB64;
        if ($this->probeAnswer instanceof \Throwable) {
            throw $this->probeAnswer;
        }

        return $this->probeAnswer ?? ['status' => 'ok', 'duration' => 20.0, 'has_audio' => true, 'reason' => null];
    }

    public function render(array $payload, string $tenantId, string $userId): array
    {
        $this->calls[] = $payload;
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer ?? ['status' => 'ok', 'bytes' => "\0\0\0\x18ftypmp42MOVIE", 'reason' => null];
    }
}
