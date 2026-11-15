<?php

declare(strict_types=1);

namespace App\Links;

/** Keeps a secret address encrypted at rest. */
interface Secrets
{
    /** @return array{cipher: string, version: int} */
    public function encrypt(string $plain): array;

    /** @throws \RuntimeException */
    public function decrypt(string $cipher, int $version): string;
}
