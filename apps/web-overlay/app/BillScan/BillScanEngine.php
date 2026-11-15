<?php

declare(strict_types=1);

namespace App\BillScan;

interface BillScanEngine
{
    /**
     * @param  list<array{code: string, name: string}>  $accounts
     * @return array<string, mixed> the engine's answer: status ok|unreadable|unsupported, data, flags, input_tokens, output_tokens ...
     *
     * @throws \App\Ai\ProviderCallException
     */
    public function scan(string $providerId, string $modelKey, string $mime, string $bytes, array $accounts, string $tenantId, string $userId): array;
}
