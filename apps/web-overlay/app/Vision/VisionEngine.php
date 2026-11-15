<?php

declare(strict_types=1);

namespace App\Vision;

interface VisionEngine
{
    /**
     * @return array<string, mixed> status ok|empty|unsupported, text, pages, source (text_layer|vision), model, input_tokens, output_tokens
     *
     * @throws \App\Ai\ProviderCallException
     */
    public function read(string $providerId, string $modelKey, string $mime, string $bytes, string $mode, string $tenantId, string $userId): array;
}
