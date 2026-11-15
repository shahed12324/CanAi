<?php

declare(strict_types=1);

namespace App\Ai;

interface ProviderStore
{
    /** @return array{id: string, adapter: string, base_url: ?string, timeout_ms: int, allow_private: bool, status: string}|null */
    public function provider(string $id): ?array;

    /** Ciphertext of the active credential (only if the provider is usable), else null. */
    public function secret(string $providerId, bool $forTest = false): ?array;
}
