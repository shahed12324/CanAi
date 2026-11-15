<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Vision\VisionEngine;

final class FakeVisionEngine implements VisionEngine
{
    /** @var array<string, mixed>|\Throwable */
    public mixed $answer;

    public int $calls = 0;

    /** @var list<array<string, mixed>> */
    public array $seen = [];

    public function __construct(mixed $answer = null)
    {
        $this->answer = $answer ?? ['status' => 'ok', 'text' => 'দামের তালিকা', 'pages' => 1, 'source' => 'vision', 'model' => 'gpt-vision', 'input_tokens' => 900, 'output_tokens' => 40];
    }

    public function read(string $providerId, string $modelKey, string $mime, string $bytes, string $mode, string $tenantId, string $userId): array
    {
        $this->calls++;
        $this->seen[] = ['model' => $modelKey, 'mime' => $mime, 'mode' => $mode, 'bytes' => strlen($bytes)];
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
