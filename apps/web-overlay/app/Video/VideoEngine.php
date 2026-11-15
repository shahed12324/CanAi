<?php

declare(strict_types=1);

namespace App\Video;

interface VideoEngine
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: string, bytes: ?string, reason: ?string} ok | unsupported
     *
     * @throws \App\Ai\ProviderCallException
     * @throws \RuntimeException when the engine cannot be reached
     */
    public function render(array $payload, string $tenantId, string $userId): array;

    /**
     * Edits an uploaded clip (trim, cut silences, fit to a size).
     *
     * @param  array<string, mixed>  $payload
     * @return array{status: string, bytes: ?string, reason: ?string}
     *
     * @throws \App\Ai\ProviderCallException
     */
    public function edit(array $payload, string $tenantId, string $userId): array;

    /**
     * @return array{status: string, duration: float, has_audio: bool, reason: ?string} ok | unsupported
     *
     * @throws \App\Ai\ProviderCallException
     */
    public function probe(string $videoB64, string $tenantId, string $userId): array;
}
