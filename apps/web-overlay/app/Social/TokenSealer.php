<?php

declare(strict_types=1);

namespace App\Social;

/** Seals and opens a Page token. */
interface TokenSealer
{
    /** @return array{cipher: string, version: int} */
    public function seal(string $token): array;

    public function open(string $cipher, int $version): ?string;
}
