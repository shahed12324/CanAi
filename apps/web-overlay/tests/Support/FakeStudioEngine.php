<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Studio\StudioEngine;

final class FakeStudioEngine implements StudioEngine
{
    /** @var list<array<string, mixed>> */
    public array $drawn = [];

    /** @var list<array<string, mixed>> */
    public array $composed = [];

    public function __construct(public mixed $imageAnswer = null, public mixed $composeAnswer = null) {}

    public function image(string $providerId, string $modelKey, string $prompt, string $size, string $tenantId, string $userId): array
    {
        $this->drawn[] = compact('providerId', 'modelKey', 'prompt', 'size', 'tenantId', 'userId');
        if ($this->imageAnswer instanceof \Throwable) {
            throw $this->imageAnswer;
        }

        return $this->imageAnswer ?? ['status' => 'ok', 'image_b64' => base64_encode('PICTURE'), 'width' => 1024, 'height' => 1024];
    }

    public function compose(array $payload, string $tenantId, string $userId): array
    {
        $this->composed[] = $payload;
        if ($this->composeAnswer instanceof \Throwable) {
            throw $this->composeAnswer;
        }
        if ($this->composeAnswer !== null) {
            return $this->composeAnswer;
        }

        return ['status' => 'ok', 'items' => array_map(fn ($s) => ['size' => $s, 'width' => 10, 'height' => 10, 'mime' => ($payload['fmt'] ?? 'png') === 'jpeg' ? 'image/jpeg' : 'image/png', 'image_b64' => base64_encode("IMG-$s")], $payload['sizes'])];
    }
}
