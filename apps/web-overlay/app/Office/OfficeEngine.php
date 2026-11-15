<?php

declare(strict_types=1);

namespace App\Office;

interface OfficeEngine
{
    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{text: string, input_tokens: ?int, output_tokens: ?int, model: string, finish_reason?: ?string}
     *
     * @throws \App\Ai\ProviderCallException
     */
    public function complete(string $providerId, string $modelKey, array $messages, string $tenantId, string $userId, int $maxTokens): array;

    /**
     * @param  array<string, mixed>  $spec  a spec made by OfficeSpec::parse
     * @return string the file's bytes
     *
     * @throws \RuntimeException when the engine could not write the file
     */
    public function build(string $kind, array $spec, string $tenantId, string $userId): string;
}
